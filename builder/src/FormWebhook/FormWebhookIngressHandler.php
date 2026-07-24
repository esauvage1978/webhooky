<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\ApplicationErrorLog;
use App\Entity\FormWebhook;
use App\Entity\FormWebhookAction;
use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\Entity\Organization;
use App\Entity\WebhookProject;
use App\FormWebhook\PayloadParser\PayloadParserChain;
use App\Mailjet\MailjetApiKeyPair;
use App\Mailjet\MailjetAuthPairInterface;
use App\Mailjet\MailjetTemplateSenderInterface;
use App\Logging\ApplicationErrorLogger;
use App\Monitoring\FormWebhookRetryService;
use App\Monitoring\MonitoringMetricBuffer;
use App\Monitoring\MonitoringMetricKeys;
use App\Repository\FormWebhookRepository;
use App\ServiceIntegration\ServiceConnectionSecretHelper;
use App\ServiceIntegration\ServiceIntegrationType;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestration : parse une fois → exécution de toutes les actions actives → journaux séparés, réponse agrégée.
 */
final class FormWebhookIngressHandler implements FormWebhookIngressHandlerInterface
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
        private readonly IntegrationActionExecutor $integrationActionExecutor,
        private readonly ApplicationErrorLogger $applicationErrorLogger,
        private readonly BuiltinWorkflowActionExecutor $builtinWorkflowActionExecutor,
        private readonly RateLimiterFactory $webhookIngestLimiter,
        private readonly ServiceConnectionSecretHelper $secretHelper,
        private readonly MonitoringMetricBuffer $metricBuffer,
        private readonly FormWebhookRetryService $retryService,
    ) {
    }

    public function handle(Request $request, string $publicToken): JsonResponse
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $clientKey = $request->getClientIp() ?: 'unknown';
        $limiter = $this->webhookIngestLimiter->create($clientKey.':'.$publicToken);
        if (!$limiter->consume(1)->isAccepted()) {
            try {
                $this->metricBuffer->increment(MonitoringMetricKeys::WEBHOOK_RATE_LIMITED, 1);
                $this->metricBuffer->flushToDatabase();
            } catch (\Throwable) {
            }

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Trop de requêtes. Réessayez dans un instant.',
                    'code' => 'rate_limited',
                    'correlationId' => $correlationId,
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $webhook = $this->formWebhookRepository->findOneByPublicTokenForIngress($publicToken);
        if ($webhook === null) {
            return new JsonResponse(['error' => 'Webhook inconnu (aucun workflow ne correspond à ce jeton).'], Response::HTTP_NOT_FOUND);
        }

        if (!$webhook->isActive()) {
            return $this->handleInactiveWorkflowIngress($webhook, $request);
        }

        if ($webhook->isDraft()) {
            return $this->handleDraftWorkflowIngress($webhook, $request);
        }

        $actions = $webhook->getActiveActionsOrdered();
        if ($actions === []) {
            $logId = $this->persistIngressRejectionLog($webhook, $request, 'Aucune action active sur ce webhook.', $correlationId);

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Aucune action active sur ce webhook.',
                    'logId' => $logId,
                    'correlationId' => $correlationId,
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $org = $webhook->getOrganization();
        if ($org !== null && !$this->subscriptionEntitlement->isEntitledToWebhooks($org)) {
            $logId = $this->persistIngressRejectionLog(
                $webhook,
                $request,
                'Abonnement inactif ou expiré pour cette organisation.',
                $correlationId,
            );

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Abonnement inactif ou expiré pour cette organisation. Réactivez un forfait pour accepter à nouveau les envois.',
                    'code' => 'subscription_inactive',
                    'logId' => $logId,
                    'correlationId' => $correlationId,
                ],
                Response::HTTP_PAYMENT_REQUIRED,
            );
        }

        $eventCount = \count($actions);
        if ($org !== null && !$this->subscriptionEntitlement->tryConsumeEvents($org, $eventCount)) {
            $snap = $this->subscriptionEntitlement->buildSnapshot($org);
            $logId = $this->persistIngressRejectionLog(
                $webhook,
                $request,
                (string) ($snap['blockReason'] ?? 'Quota d’événements épuisé.'),
                $correlationId,
            );

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $snap['blockReason'] ?? 'Quota d’événements épuisé. Augmentez votre forfait ou achetez un pack.',
                    'code' => 'events_quota_exceeded',
                    'subscription' => $snap,
                    'logId' => $logId,
                    'correlationId' => $correlationId,
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
        $log->setCorrelationId($correlationId);
        $log->setQuotaUnitsConsumed($eventCount);

        $orgId = $org instanceof Organization ? (int) $org->getId() : null;
        $this->safeMetric(MonitoringMetricKeys::WEBHOOK_RECEIVED, 1, $orgId);

        $t0 = (int) round(microtime(true) * 1000);

        try {
            $parsed = $this->payloadParserChain->parse($request);
            $log->setParsedInput($parsed);
            $log->setStatus(FormWebhookLogStatus::PARSED);
        } catch (\Throwable $e) {
            $this->applicationErrorLogger->logThrowable($e, $request, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'form_webhook_ingress_parse',
                'formWebhookId' => $webhook->getId(),
                'formWebhookLogId' => null,
                'correlationId' => $correlationId,
            ]);
            $log->setStatus(FormWebhookLogStatus::ERROR);
            $log->setErrorDetail($e->getMessage());
            $this->persistLog($log, $t0);
            $this->safeMetric(MonitoringMetricKeys::WEBHOOK_RUN_ERROR, 1, $orgId);
            $this->safeFlushMetrics();
            $this->runNotifier->notifyAfterRun($webhook, $log, false);

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'logId' => $log->getId(),
                    'correlationId' => $correlationId,
                    'actions' => [],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $actionPayloads = [];
        $allOk = true;

        $workflowContext = [
            'organization_id' => $org instanceof Organization ? (int) $org->getId() : 0,
            'user_id' => $webhook->getCreatedBy()?->getId(),
            'workflow_id' => $webhook->getId(),
            'data' => [],
        ];
        $skipNext = 0;
        $project = $webhook->getProject();

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
                if ($skipNext > 0) {
                    --$skipNext;
                    $aLog->setStatus(FormWebhookLogStatus::SKIPPED);
                    $aLog->setErrorDetail('Étape ignorée (condition du pipeline).');
                    $entry['ok'] = true;
                    $entry['skipped'] = true;
                    $entry['actionType'] = $action->getActionType();
                    $aLog->setDurationMs((int) round(microtime(true) * 1000) - $tAction);
                    $actionPayloads[] = $entry;

                    continue;
                }

                if (WorkflowBuiltinActionType::isBuiltin($action->getActionType())) {
                    if (!$org instanceof Organization) {
                        throw new \RuntimeException('Organisation requise pour les actions SEO du pipeline.');
                    }
                    if (!$project instanceof WebhookProject) {
                        throw new \RuntimeException('Projet requis pour les actions SEO du pipeline.');
                    }
                    $exec = $this->builtinWorkflowActionExecutor->execute($action, $project, $parsed, $workflowContext, $aLog);
                    $skipNext = (int) ($exec['skip_next'] ?? 0);
                    $aLog->setStatus(FormWebhookLogStatus::SENT);
                    $entry['ok'] = true;
                    $entry['actionType'] = $action->getActionType();
                    $entry['pipeline'] = [
                        'dataKeys' => array_keys($workflowContext['data'] ?? []),
                    ];
                } elseif ($action->getActionType() === ServiceIntegrationType::MAILJET) {
                    [$toEmail, $toName] = $this->recipientResolver->resolve($action, $parsed);
                    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new \InvalidArgumentException('Destinataire invalide pour une action : renseignez recipientEmailPostKey ou defaultRecipientEmail.');
                    }
                    $aLog->setRecipient($toEmail);

                    $variables = $this->variableMapBuilder->build($parsed, $action->getVariableMapping());
                    $aLog->setVariablesSent($variables);

                    $mailjetAuth = $this->resolveMailjetAuth($action);
                    if ($mailjetAuth === null) {
                        throw new \RuntimeException('Configuration Mailjet manquante pour une action.');
                    }

                    $result = $this->mailjetTemplateSender->sendTemplate(
                        $mailjetAuth,
                        $action->getMailjetTemplateId(),
                        $action->isTemplateLanguage(),
                        $toEmail,
                        $toName,
                        $variables,
                    );

                    $aLog->setHttpStatus($result->getHttpStatus());
                    $truncResp = $result->getRawResponseBody();
                    $aLog->setProviderResponseBody($truncResp !== null ? mb_substr($truncResp, 0, 16000) : null);
                    $aLog->setProviderMessageId($result->getMessageId());

                    if (!$result->isSuccess()) {
                        $allOk = false;
                        $aLog->setStatus(FormWebhookLogStatus::ERROR);
                        $aLog->setErrorDetail($result->getErrorMessage() ?? 'Erreur Mailjet');
                        $entry['error'] = $aLog->getErrorDetail();
                        $entry['mailjetHttpStatus'] = $result->getHttpStatus();
                        $this->safeMetric(MonitoringMetricKeys::WEBHOOK_ACTION_ERROR, 1, $orgId, ['actionType' => ServiceIntegrationType::MAILJET]);
                        if ($this->retryService->isRetryable($aLog)) {
                            $this->retryService->scheduleRetry($aLog, 2);
                            $entry['retryScheduled'] = true;
                        }
                    } else {
                        $aLog->setStatus(FormWebhookLogStatus::SENT);
                        $entry['ok'] = true;
                        $entry['messageId'] = $result->getMessageId();
                    }
                } else {
                    $this->integrationActionExecutor->execute($action, $parsed, $aLog);
                    $aLog->setStatus(FormWebhookLogStatus::SENT);
                    $entry['ok'] = true;
                    $entry['actionType'] = $action->getActionType();
                    if ($aLog->getMailjetHttpStatus() !== null) {
                        $entry['httpStatus'] = $aLog->getMailjetHttpStatus();
                    }
                }
            } catch (\Throwable $e) {
                $this->applicationErrorLogger->logThrowable($e, $request, ApplicationErrorLog::SOURCE_HANDLED, [
                    'handler' => 'form_webhook_ingress_action',
                    'formWebhookId' => $webhook->getId(),
                    'formWebhookActionId' => $action->getId(),
                    'actionType' => $action->getActionType(),
                    'correlationId' => $correlationId,
                ]);
                $allOk = false;
                $aLog->setStatus(FormWebhookLogStatus::ERROR);
                $aLog->setErrorDetail($e->getMessage());
                $entry['error'] = $e->getMessage();
                $entry['actionType'] = $action->getActionType();
                $st = $aLog->getMailjetHttpStatus();
                if ($st !== null) {
                    $entry['mailjetHttpStatus'] = $st;
                    $entry['httpStatus'] = $st;
                }
                $this->safeMetric(MonitoringMetricKeys::WEBHOOK_ACTION_ERROR, 1, $orgId, ['actionType' => (string) $action->getActionType()]);
                if ($this->retryService->isRetryable($aLog)) {
                    $this->retryService->scheduleRetry($aLog, 2);
                    $entry['retryScheduled'] = true;
                }
            }

            $aLog->setDurationMs((int) round(microtime(true) * 1000) - $tAction);
            if ($aLog->getStatus() === FormWebhookLogStatus::SENT) {
                $this->safeMetric(MonitoringMetricKeys::WEBHOOK_ACTION_SUCCESS, 1, $orgId, ['actionType' => (string) $action->getActionType()]);
            }
            $actionPayloads[] = $entry;
        }

        $hasRetry = false;
        foreach ($log->getActionLogs() as $al) {
            if ($al->getStatus() === FormWebhookLogStatus::RETRY_SCHEDULED) {
                $hasRetry = true;
                break;
            }
        }
        if ($hasRetry) {
            $log->setStatus(FormWebhookLogStatus::RETRY_SCHEDULED);
        } else {
            $log->setStatus($allOk ? FormWebhookLogStatus::SENT : FormWebhookLogStatus::ERROR);
        }
        if (!$allOk && !$hasRetry) {
            $log->setErrorDetail('Une ou plusieurs actions ont échoué.');
        }
        $this->persistLog($log, $t0);
        $this->safeMetric(
            $allOk ? MonitoringMetricKeys::WEBHOOK_RUN_SUCCESS : ($hasRetry ? MonitoringMetricKeys::WEBHOOK_RETRY_SCHEDULED : MonitoringMetricKeys::WEBHOOK_RUN_ERROR),
            1,
            $orgId,
        );
        if ($log->getDurationMs() !== null) {
            $this->safeMetric(MonitoringMetricKeys::WEBHOOK_DURATION_MS, (float) $log->getDurationMs(), $orgId);
        }
        $this->safeFlushMetrics();

        $body = [
            'ok' => $allOk,
            'logId' => $log->getId(),
            'correlationId' => $correlationId,
            'actions' => $actionPayloads,
        ];
        if (!$allOk) {
            $body['error'] = $log->getErrorDetail();
        }

        $this->runNotifier->notifyAfterRun($webhook, $log, $allOk);

        return new JsonResponse($body, $allOk ? Response::HTTP_OK : $this->failureHttpStatus($actionPayloads));
    }

    /**
     * Workflow désactivé : mêmes métadonnées + parsing que l’ingress normal, sans actions ni quota d’événements.
     */
    private function handleInactiveWorkflowIngress(FormWebhook $webhook, Request $request): JsonResponse
    {
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
            $log->setStatus(FormWebhookLogStatus::SKIPPED);
        } catch (\Throwable $e) {
            $this->applicationErrorLogger->logThrowable($e, $request, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'form_webhook_inactive_parse',
                'formWebhookId' => $webhook->getId(),
            ]);
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

        $this->persistLog($log, $t0);

        return new JsonResponse(
            [
                'ok' => true,
                'skipped' => true,
                'code' => 'workflow_inactive',
                'logId' => $log->getId(),
                'message' => 'Workflow désactivé : réception enregistrée, aucune action exécutée.',
                'actions' => [],
            ],
            Response::HTTP_OK,
        );
    }

    /**
     * Brouillon : même traçabilité que l’ingress normal (parse + journal), sans actions ni quota d’événements.
     */
    private function handleDraftWorkflowIngress(FormWebhook $webhook, Request $request): JsonResponse
    {
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
            $log->setStatus(FormWebhookLogStatus::SKIPPED);
        } catch (\Throwable $e) {
            $this->applicationErrorLogger->logThrowable($e, $request, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'form_webhook_draft_parse',
                'formWebhookId' => $webhook->getId(),
            ]);
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

        $this->persistLog($log, $t0);

        return new JsonResponse(
            [
                'ok' => true,
                'skipped' => true,
                'code' => 'workflow_draft',
                'logId' => $log->getId(),
                'message' => 'Workflow en brouillon : réception enregistrée, aucune action exécutée.',
                'actions' => [],
            ],
            Response::HTTP_OK,
        );
    }

    private function resolveMailjetAuth(FormWebhookAction $action): ?MailjetAuthPairInterface
    {
        $entityMj = $action->getMailjet();
        if ($entityMj instanceof \App\Entity\Mailjet) {
            return $entityMj;
        }

        $sc = $action->getServiceConnection();
        if ($sc === null || $sc->getType() !== ServiceIntegrationType::MAILJET) {
            return null;
        }

        $cfg = $this->secretHelper->decryptSensitiveFields($sc->getConfig());
        $pub = isset($cfg['apiKeyPublic']) ? trim((string) $cfg['apiKeyPublic']) : '';
        $priv = isset($cfg['apiKeyPrivate']) ? trim((string) $cfg['apiKeyPrivate']) : '';
        if ($pub === '' || $priv === '') {
            return null;
        }

        return new MailjetApiKeyPair($pub, $priv);
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
            if (isset($p['httpStatus']) && \is_int($p['httpStatus']) && $p['httpStatus'] >= 400) {
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

    /**
     * Journalise une réception refusée avant exécution des actions (traçabilité dans l’UI).
     */
    private function persistIngressRejectionLog(
        FormWebhook $webhook,
        Request $request,
        string $errorDetail,
        ?string $correlationId = null,
    ): int {
        $log = new FormWebhookLog();
        $log->setFormWebhook($webhook);
        $log->setClientIp($request->getClientIp());
        $log->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 512));
        $log->setContentType((string) $request->headers->get('Content-Type', ''));
        $log->setRawBody($this->truncateRaw($request->getContent()));
        $log->setStatus(FormWebhookLogStatus::ERROR);
        $log->setErrorDetail($errorDetail);
        if ($correlationId !== null) {
            $log->setCorrelationId($correlationId);
        }
        $t0 = (int) round(microtime(true) * 1000);
        $this->persistLog($log, $t0);
        $orgId = $webhook->getOrganization()?->getId();
        $this->safeMetric(MonitoringMetricKeys::WEBHOOK_RECEIVED, 1, $orgId);
        $this->safeMetric(MonitoringMetricKeys::WEBHOOK_RUN_ERROR, 1, $orgId);
        $this->safeFlushMetrics();

        return (int) $log->getId();
    }

    private function truncateRaw(string $raw): string
    {
        if (mb_strlen($raw) <= self::RAW_BODY_MAX_LEN) {
            return $raw;
        }

        return mb_substr($raw, 0, self::RAW_BODY_MAX_LEN)."\n… [tronqué]";
    }

    /**
     * @param array<string, scalar> $dims
     */
    private function safeMetric(string $key, float $value = 1.0, ?int $orgId = null, array $dims = []): void
    {
        try {
            $this->metricBuffer->increment($key, $value, $orgId, $dims);
        } catch (\Throwable) {
        }
    }

    private function safeFlushMetrics(): void
    {
        try {
            $this->metricBuffer->flushToDatabase();
        } catch (\Throwable) {
        }
    }
}
