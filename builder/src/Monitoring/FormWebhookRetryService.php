<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\Entity\Organization;
use App\Entity\WebhookProject;
use App\FormWebhook\BuiltinWorkflowActionExecutor;
use App\FormWebhook\FormWebhookLogStatus;
use App\FormWebhook\IntegrationActionExecutor;
use App\FormWebhook\RecipientResolver;
use App\FormWebhook\VariableMapBuilder;
use App\FormWebhook\WorkflowBuiltinActionType;
use App\Mailjet\MailjetApiKeyPair;
use App\Mailjet\MailjetTemplateSenderInterface;
use App\Message\RetryFormWebhookActionMessage;
use App\Repository\FormWebhookActionLogRepository;
use App\Repository\MonitoringSettingRepository;
use App\ServiceIntegration\ServiceConnectionSecretHelper;
use App\ServiceIntegration\ServiceIntegrationType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class FormWebhookRetryService
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormWebhookActionLogRepository $actionLogRepository,
        private readonly MonitoringSettingRepository $settingRepository,
        private readonly MonitoringMetricBuffer $metricBuffer,
        private readonly BuiltinWorkflowActionExecutor $builtinWorkflowActionExecutor,
        private readonly IntegrationActionExecutor $integrationActionExecutor,
        private readonly MailjetTemplateSenderInterface $mailjetTemplateSender,
        private readonly RecipientResolver $recipientResolver,
        private readonly VariableMapBuilder $variableMapBuilder,
        private readonly ServiceConnectionSecretHelper $secretHelper,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function maxAttempts(): int
    {
        $cfg = $this->settingRepository->getValue('retry', ['maxAttempts' => 3]);

        return max(1, (int) ($cfg['maxAttempts'] ?? 3));
    }

    public function isRetryable(FormWebhookActionLog $actionLog): bool
    {
        if ($actionLog->getStatus() !== FormWebhookLogStatus::ERROR) {
            return false;
        }
        if ($actionLog->getAttempt() >= $this->maxAttempts()) {
            return false;
        }
        $http = $actionLog->getHttpStatus();
        if ($http !== null && $http >= 400 && $http < 500 && $http !== 429) {
            return false;
        }
        $msg = strtolower((string) $actionLog->getErrorDetail());
        if (str_contains($msg, 'invalid') || str_contains($msg, 'destinataire') || str_contains($msg, 'configuration')) {
            return false;
        }

        return true;
    }

    public function scheduleRetry(FormWebhookActionLog $actionLog, int $nextAttempt): bool
    {
        try {
            $delayMs = (int) (5000 * (2 ** max(0, $nextAttempt - 2)));
            $delayMs = min(120000, max(2000, $delayMs));
            $actionLog->setStatus(FormWebhookLogStatus::RETRY_SCHEDULED);
            $parent = $actionLog->getFormWebhookLog();
            if ($parent instanceof FormWebhookLog) {
                $parent->setStatus(FormWebhookLogStatus::RETRY_SCHEDULED);
                $parent->setAttemptCount(max($parent->getAttemptCount(), $nextAttempt));
            }
            $this->entityManager->flush();
            $this->messageBus->dispatch(
                new RetryFormWebhookActionMessage((int) $actionLog->getId(), $nextAttempt),
                [new DelayStamp($delayMs)],
            );
            $orgId = $parent?->getFormWebhook()?->getOrganization()?->getId();
            $this->metricBuffer->increment(MonitoringMetricKeys::WEBHOOK_RETRY_SCHEDULED, 1, $orgId);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('monitoring.retry.schedule_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function markDeadLetter(FormWebhookActionLog $actionLog): void
    {
        $actionLog->setStatus(FormWebhookLogStatus::DEAD_LETTER);
        $parent = $actionLog->getFormWebhookLog();
        if ($parent instanceof FormWebhookLog) {
            $parent->setStatus(FormWebhookLogStatus::DEAD_LETTER);
            $parent->setErrorDetail('Échec définitif après retries (dead letter).');
        }
        $orgId = $parent?->getFormWebhook()?->getOrganization()?->getId();
        $this->metricBuffer->increment(MonitoringMetricKeys::WEBHOOK_DEAD_LETTER, 1, $orgId);
        $this->entityManager->flush();
    }

    public function executeRetry(int $actionLogId, int $attempt): void
    {
        $actionLog = $this->actionLogRepository->find($actionLogId);
        if (!$actionLog instanceof FormWebhookActionLog) {
            return;
        }
        $parent = $actionLog->getFormWebhookLog();
        $action = $actionLog->getFormWebhookAction();
        if ($parent === null || $action === null) {
            $this->markDeadLetter($actionLog);

            return;
        }
        $webhook = $parent->getFormWebhook();
        $parsed = $parent->getParsedInput() ?? [];
        $actionLog->setAttempt($attempt);
        $actionLog->setStatus(FormWebhookLogStatus::PARSED);
        $actionLog->setErrorDetail(null);
        $t0 = (int) round(microtime(true) * 1000);

        try {
            $org = $webhook?->getOrganization();
            $project = $webhook?->getProject();
            if (WorkflowBuiltinActionType::isBuiltin($action->getActionType())) {
                if (!$org instanceof Organization || !$project instanceof WebhookProject) {
                    throw new \RuntimeException('Contexte org/projet manquant pour retry builtin.');
                }
                $ctx = [
                    'organization_id' => (int) $org->getId(),
                    'user_id' => $webhook->getCreatedBy()?->getId(),
                    'workflow_id' => $webhook->getId(),
                    'data' => [],
                ];
                $this->builtinWorkflowActionExecutor->execute($action, $project, $parsed, $ctx, $actionLog);
                $actionLog->setStatus(FormWebhookLogStatus::SENT);
            } elseif ($action->getActionType() === ServiceIntegrationType::MAILJET) {
                [$toEmail, $toName] = $this->recipientResolver->resolve($action, $parsed);
                $actionLog->setRecipient($toEmail);
                $variables = $this->variableMapBuilder->build($parsed, $action->getVariableMapping());
                $actionLog->setVariablesSent($variables);
                $sc = $action->getServiceConnection();
                if ($sc === null) {
                    throw new \RuntimeException('Connecteur Mailjet manquant.');
                }
                $cfg = $this->secretHelper->decryptSensitiveFields($sc->getConfig());
                $auth = new MailjetApiKeyPair(
                    trim((string) ($cfg['apiKeyPublic'] ?? '')),
                    trim((string) ($cfg['apiKeyPrivate'] ?? '')),
                );
                $result = $this->mailjetTemplateSender->sendTemplate(
                    $auth,
                    $action->getMailjetTemplateId(),
                    $action->isTemplateLanguage(),
                    $toEmail,
                    $toName,
                    $variables,
                );
                $actionLog->setHttpStatus($result->getHttpStatus());
                $actionLog->setProviderResponseBody($result->getRawResponseBody() !== null ? mb_substr($result->getRawResponseBody(), 0, 16000) : null);
                $actionLog->setProviderMessageId($result->getMessageId());
                if (!$result->isSuccess()) {
                    throw new \RuntimeException($result->getErrorMessage() ?? 'Erreur Mailjet');
                }
                $actionLog->setStatus(FormWebhookLogStatus::SENT);
            } else {
                $this->integrationActionExecutor->execute($action, $parsed, $actionLog);
                $actionLog->setStatus(FormWebhookLogStatus::SENT);
            }
            $this->refreshParentStatus($parent);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $actionLog->setStatus(FormWebhookLogStatus::ERROR);
            $actionLog->setErrorDetail($e->getMessage());
            $actionLog->setDurationMs((int) round(microtime(true) * 1000) - $t0);
            $this->entityManager->flush();
            if ($this->isRetryable($actionLog)) {
                $this->scheduleRetry($actionLog, $attempt + 1);
            } else {
                $this->markDeadLetter($actionLog);
            }
        } finally {
            if ($actionLog->getDurationMs() === null) {
                $actionLog->setDurationMs((int) round(microtime(true) * 1000) - $t0);
                $this->entityManager->flush();
            }
            $this->metricBuffer->flushToDatabase();
        }
    }

    private function refreshParentStatus(FormWebhookLog $parent): void
    {
        $hasError = false;
        $hasRetry = false;
        $hasDead = false;
        foreach ($parent->getActionLogs() as $al) {
            $st = $al->getStatus();
            if ($st === FormWebhookLogStatus::DEAD_LETTER) {
                $hasDead = true;
            } elseif ($st === FormWebhookLogStatus::RETRY_SCHEDULED) {
                $hasRetry = true;
            } elseif ($st === FormWebhookLogStatus::ERROR) {
                $hasError = true;
            }
        }
        if ($hasDead) {
            $parent->setStatus(FormWebhookLogStatus::DEAD_LETTER);
        } elseif ($hasRetry) {
            $parent->setStatus(FormWebhookLogStatus::RETRY_SCHEDULED);
        } elseif ($hasError) {
            $parent->setStatus(FormWebhookLogStatus::ERROR);
        } else {
            $parent->setStatus(FormWebhookLogStatus::SENT);
            $parent->setErrorDetail(null);
        }
    }
}
