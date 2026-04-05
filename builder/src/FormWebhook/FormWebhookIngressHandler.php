<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\FormWebhook\PayloadParser\PayloadParserChain;
use App\Mailjet\MailjetTemplateSenderInterface;
use App\Repository\FormWebhookRepository;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orchestration : parse une fois → exécution de toutes les actions actives → journaux séparés, réponse agrégée.
 */
final class FormWebhookIngressHandler
{
    private const RAW_BODY_MAX_LEN = 65000;

    public function __construct(
        private readonly FormWebhookRepository $formWebhookRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PayloadParserChain $payloadParserChain,
        private readonly VariableMapBuilder $variableMapBuilder,
        private readonly RecipientResolver $recipientResolver,
        private readonly MailjetTemplateSenderInterface $mailjetTemplateSender,
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
        private readonly FormWebhookRunNotifier $runNotifier,
    ) {
    }

    public function handle(Request $request, string $publicToken): JsonResponse
    {
        $webhook = $this->formWebhookRepository->findActiveByPublicToken($publicToken);
        if ($webhook === null) {
            return new JsonResponse(['error' => 'Webhook inconnu ou inactif'], Response::HTTP_NOT_FOUND);
        }

        $actions = $webhook->getActiveActionsOrdered();
        if ($actions === []) {
            return new JsonResponse(['ok' => false, 'error' => 'Aucune action active sur ce webhook.'], Response::HTTP_BAD_REQUEST);
        }

        $org = $webhook->getOrganization();
        if ($org !== null && !$this->subscriptionEntitlement->isEntitledToWebhooks($org)) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Abonnement inactif ou expiré pour cette organisation. Réactivez un forfait pour accepter à nouveau les envois.',
                    'code' => 'subscription_inactive',
                ],
                Response::HTTP_PAYMENT_REQUIRED,
            );
        }

        $eventCount = \count($actions);
        if ($org !== null && !$this->subscriptionEntitlement->tryConsumeEvents($org, $eventCount)) {
            $snap = $this->subscriptionEntitlement->buildSnapshot($org);

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $snap['blockReason'] ?? 'Quota d’événements épuisé. Augmentez votre forfait ou achetez un pack.',
                    'code' => 'events_quota_exceeded',
                    'subscription' => $snap,
                ],
                Response::HTTP_PAYMENT_REQUIRED,
            );
        }

        $log = new FormWebhookLog();
        $log->setFormWebhook($webhook);
        $log->setClientIp($request->getClientIp());
        $log->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 512));
        $log->setContentType((string) $request->headers->get('Content-Type', ''));
        $log->setRawBody($this->truncateRaw($request->getContent()));
        $log->setStatus(FormWebhookLogStatus::RECEIVED);

        $t0 = (int) round(microtime(true) * 1000);

        try {
            $parsed = $this->payloadParserChain->parse($request);
            $log->setParsedInput($parsed);
            $log->setStatus(FormWebhookLogStatus::PARSED);
        } catch (\Throwable $e) {
            $log->setStatus(FormWebhookLogStatus::ERROR);
            $log->setErrorDetail($e->getMessage());
            $this->persistLog($log, $t0);
            $this->runNotifier->notifyAfterRun($webhook, $log, false);

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'logId' => $log->getId(),
                    'actions' => [],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $actionPayloads = [];
        $allOk = true;

        foreach ($actions as $action) {
            $aLog = new FormWebhookActionLog();
            $aLog->setFormWebhookAction($action);
            $aLog->setSortOrder($action->getSortOrder());
            $aLog->setStatus(FormWebhookLogStatus::PARSED);
            $log->addActionLog($aLog);

            $tAction = (int) round(microtime(true) * 1000);
            $entry = [
                'actionId' => $action->getId(),
                'ok' => false,
            ];

            try {
                [$toEmail, $toName] = $this->recipientResolver->resolve($action, $parsed);
                if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Destinataire invalide pour une action : renseignez recipientEmailPostKey ou defaultRecipientEmail.');
                }
                $aLog->setToEmail($toEmail);

                $variables = $this->variableMapBuilder->build($parsed, $action->getVariableMapping());
                $aLog->setVariablesSent($variables);

                $mailjetConfig = $action->getMailjet();
                if ($mailjetConfig === null) {
                    throw new \RuntimeException('Configuration Mailjet manquante pour une action.');
                }

                $result = $this->mailjetTemplateSender->sendTemplate(
                    $mailjetConfig,
                    $action->getMailjetTemplateId(),
                    $action->isTemplateLanguage(),
                    $toEmail,
                    $toName,
                    $variables,
                );

                $aLog->setMailjetHttpStatus($result->getHttpStatus());
                $truncResp = $result->getRawResponseBody();
                $aLog->setMailjetResponseBody($truncResp !== null ? mb_substr($truncResp, 0, 16000) : null);
                $aLog->setMailjetMessageId($result->getMessageId());

                if (!$result->isSuccess()) {
                    $allOk = false;
                    $aLog->setStatus(FormWebhookLogStatus::ERROR);
                    $aLog->setErrorDetail($result->getErrorMessage() ?? 'Erreur Mailjet');
                    $entry['error'] = $aLog->getErrorDetail();
                    $entry['mailjetHttpStatus'] = $result->getHttpStatus();
                } else {
                    $aLog->setStatus(FormWebhookLogStatus::SENT);
                    $entry['ok'] = true;
                    $entry['messageId'] = $result->getMessageId();
                }
            } catch (\Throwable $e) {
                $allOk = false;
                $aLog->setStatus(FormWebhookLogStatus::ERROR);
                $aLog->setErrorDetail($e->getMessage());
                $entry['error'] = $e->getMessage();
            }

            $aLog->setDurationMs((int) round(microtime(true) * 1000) - $tAction);
            $actionPayloads[] = $entry;
        }

        $log->setStatus($allOk ? FormWebhookLogStatus::SENT : FormWebhookLogStatus::ERROR);
        if (!$allOk) {
            $log->setErrorDetail('Une ou plusieurs actions ont échoué.');
        }
        $this->persistLog($log, $t0);

        $body = [
            'ok' => $allOk,
            'logId' => $log->getId(),
            'actions' => $actionPayloads,
        ];
        if (!$allOk) {
            $body['error'] = $log->getErrorDetail();
        }

        $this->runNotifier->notifyAfterRun($webhook, $log, $allOk);

        return new JsonResponse($body, $allOk ? Response::HTTP_OK : $this->failureHttpStatus($actionPayloads));
    }

    /**
     * @param list<array<string, mixed>> $actionPayloads
     */
    private function failureHttpStatus(array $actionPayloads): int
    {
        foreach ($actionPayloads as $p) {
            if (isset($p['mailjetHttpStatus']) && \is_int($p['mailjetHttpStatus']) && $p['mailjetHttpStatus'] >= 400) {
                return Response::HTTP_BAD_GATEWAY;
            }
        }

        return Response::HTTP_BAD_REQUEST;
    }

    private function persistLog(FormWebhookLog $log, int $t0Ms): void
    {
        $log->setDurationMs((int) round(microtime(true) * 1000) - $t0Ms);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function truncateRaw(string $raw): string
    {
        if (mb_strlen($raw) <= self::RAW_BODY_MAX_LEN) {
            return $raw;
        }

        return mb_substr($raw, 0, self::RAW_BODY_MAX_LEN)."\n… [tronqué]";
    }
}
