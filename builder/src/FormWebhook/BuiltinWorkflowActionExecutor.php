<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhookAction;
use App\Entity\FormWebhookActionLog;
use App\Entity\Organization;
use App\Entity\WebhookProject;
use App\Service\AI\AIActionService;
use App\Service\AI\SeoPromptRegistry;
use App\Service\SEO\GoogleSearchConsoleService;

/**
 * Exécute les actions natives (GSC, IA, parse JSON, branchements).
 */
final class BuiltinWorkflowActionExecutor
{
    public function __construct(
        private readonly PipelineContextInterpolator $pipelineInterpolator,
        private readonly GoogleSearchConsoleService $googleSearchConsoleService,
        private readonly AIActionService $aiActionService,
    ) {
    }

    /**
     * @param array{organization_id: int, user_id: int|null, workflow_id: int|null, data: array<string, mixed>} $context
     *
     * @return array{skip_next: int}
     */
    public function execute(
        FormWebhookAction $action,
        WebhookProject $project,
        array $parsed,
        array &$context,
        FormWebhookActionLog $aLog,
    ): array {
        $organization = $project->getOrganization();
        if (!$organization instanceof Organization) {
            throw new \RuntimeException('Organisation manquante pour ce projet.');
        }
        $oid = $organization->getId();
        if ($oid === null || (int) $oid !== (int) ($context['organization_id'] ?? 0)) {
            throw new \RuntimeException('Incohérence d’organisation sur l’action pipeline.');
        }

        $type = $action->getActionType();
        /** @var array<string, mixed> $cfg */
        $cfg = $action->getPipelineConfig() ?? [];

        return match ($type) {
            WorkflowBuiltinActionType::GSC_FETCH => $this->runGscFetch($project, $parsed, $context, $aLog, $cfg),
            WorkflowBuiltinActionType::AI_ACTION => $this->runAi($organization, $parsed, $context, $aLog, $cfg),
            WorkflowBuiltinActionType::PARSE_JSON => $this->runParseJson($parsed, $context, $aLog, $cfg),
            WorkflowBuiltinActionType::IF => $this->runIf($parsed, $context, $aLog, $cfg),
            default => throw new \RuntimeException('Type d’action interne inconnu : '.$type),
        };
    }

    /**
     * @param array<string, mixed>                                                                                $cfg
     * @param array{organization_id: int, user_id: int|null, workflow_id: int|null, data: array<string, mixed>} $context
     *
     * @return array{skip_next: int}
     */
    private function runGscFetch(
        WebhookProject $project,
        array $parsed,
        array &$context,
        FormWebhookActionLog $aLog,
        array $cfg,
    ): array {
        $tpl = isset($cfg['url']) ? (string) $cfg['url'] : '';
        $pageFilter = $this->pipelineInterpolator->interpolateTemplate($tpl, $parsed, $context['data'] ?? []);
        $result = $this->googleSearchConsoleService->getTopQueries($project, $pageFilter);
        $context['data']['gsc_fetch'] = $result;
        $context['data']['gsc_keywords'] = json_encode($result['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $aLog->setVariablesSent(['gsc_keyword_count' => (string) \count($result['keywords'] ?? [])]);
        $aLog->setMailjetResponseBody(mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, 16000));

        return ['skip_next' => 0];
    }

    /**
     * @param array<string, mixed>                                                                                $cfg
     * @param array{organization_id: int, user_id: int|null, workflow_id: int|null, data: array<string, mixed>} $context
     *
     * @return array{skip_next: int}
     */
    private function runAi(
        Organization $organization,
        array $parsed,
        array &$context,
        FormWebhookActionLog $aLog,
        array $cfg,
    ): array {
        $promptId = isset($cfg['promptId']) ? (string) $cfg['promptId'] : SeoPromptRegistry::SEO_CORRECTOR_V3;
        $mapRaw = $cfg['promptVariables'] ?? [];
        if (!\is_array($mapRaw)) {
            $mapRaw = [];
        }
        $vars = [];
        foreach ($mapRaw as $k => $tpl) {
            $key = (string) $k;
            $vars[$key] = $this->pipelineInterpolator->interpolateTemplate((string) $tpl, $parsed, $context['data'] ?? []);
        }
        $text = $this->aiActionService->runPrompt($organization, $promptId, $vars);
        $context['data']['last_ai_response'] = $text;
        $aLog->setVariablesSent(['promptId' => $promptId, 'chars' => (string) mb_strlen($text)]);
        $aLog->setMailjetResponseBody(mb_substr($text, 0, 16000));

        return ['skip_next' => 0];
    }

    /**
     * @param array<string, mixed>                                                                                $cfg
     * @param array{organization_id: int, user_id: int|null, workflow_id: int|null, data: array<string, mixed>} $context
     *
     * @return array{skip_next: int}
     */
    private function runParseJson(array $parsed, array &$context, FormWebhookActionLog $aLog, array $cfg): array
    {
        $sourceTpl = isset($cfg['source']) ? (string) $cfg['source'] : '{{data.last_ai_response}}';
        $raw = $this->pipelineInterpolator->interpolateTemplate($sourceTpl, $parsed, $context['data'] ?? []);
        $decoded = json_decode(trim($raw), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('parse_json : JSON invalide.');
        }
        $target = isset($cfg['targetKey']) ? trim((string) $cfg['targetKey']) : 'seo';
        if ($target === '') {
            $target = 'seo';
        }
        $context['data'][$target] = $decoded;
        $aLog->setVariablesSent(['targetKey' => $target]);
        $aLog->setMailjetResponseBody(mb_substr(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, 16000));

        return ['skip_next' => 0];
    }

    /**
     * @param array<string, mixed>                                                                                $cfg
     * @param array{organization_id: int, user_id: int|null, workflow_id: int|null, data: array<string, mixed>} $context
     *
     * @return array{skip_next: int}
     */
    private function runIf(array $parsed, array &$context, FormWebhookActionLog $aLog, array $cfg): array
    {
        $leftTpl = isset($cfg['left']) ? (string) $cfg['left'] : '';
        $op = isset($cfg['operator']) ? strtolower((string) $cfg['operator']) : 'lt';
        $rightTpl = isset($cfg['right']) ? (string) $cfg['right'] : '';
        $skip = isset($cfg['skipIfFalse']) ? max(0, (int) $cfg['skipIfFalse']) : 0;

        $left = $this->pipelineInterpolator->interpolateTemplate($leftTpl, $parsed, $context['data'] ?? []);
        $right = $this->pipelineInterpolator->interpolateTemplate($rightTpl, $parsed, $context['data'] ?? []);
        $ok = $this->compareValues($left, $op, $right);
        $aLog->setVariablesSent([
            'if_result' => $ok ? 'true' : 'false',
            'skip_if_false' => (string) $skip,
        ]);

        return ['skip_next' => $ok ? 0 : $skip];
    }

    private function compareValues(string $left, string $op, string $right): bool
    {
        if ($op === 'eq' || $op === '==') {
            return $left === $right;
        }
        $ln = is_numeric($left) ? (float) $left : null;
        $rn = is_numeric($right) ? (float) $right : null;
        if ($ln === null || $rn === null) {
            return false;
        }

        return match ($op) {
            'lt', '<' => $ln < $rn,
            'lte', '<=' => $ln <= $rn,
            'gt', '>' => $ln > $rn,
            'gte', '>=' => $ln >= $rn,
            default => false,
        };
    }
}
