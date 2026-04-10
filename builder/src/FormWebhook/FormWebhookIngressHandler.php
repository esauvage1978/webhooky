<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\ApplicationErrorLog;
use App\Entity\FormWebhook;
use App\Entity\FormWebhookAction;
use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\FormWebhook\PayloadParser\PayloadParserChain;
use App\Mailjet\MailjetApiKeyPair;
use App\Mailjet\MailjetAuthPairInterface;
use App\Mailjet\MailjetTemplateSenderInterface;
use App\Logging\ApplicationErrorLogger;
use App\Repository\FormWebhookRepository;
use App\ServiceIntegration\ServiceIntegrationType;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
    ) {
    }

    public function handle(Request $request, string $publicToken): JsonResponse
    {
        $webhook = $this->formWebhookRepository->findOneByPublicTokenForIngress($publicToken);
        if ($webhook === null) {
            return new JsonResponse(['error' => 'Webhook inconnu (aucun workflow ne correspond à ce jeton).'], Response::HTTP_NOT_FOUND);
        }

        if (!$webhook->isActive()) {
            return $this->handleInactiveWorkflowIngress($webhook, $request);
        }

        $actions = $webhook->getActiveActionsOrdered();
        if ($actions === []) {
            $logId = $this->persistIngressRejectionLog($webhook, $request, 'Aucune action active sur ce webhook.');

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Aucune action active sur ce webhook.',
                    'logId' => $logId,
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
            );

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'Abonnement inactif ou expiré pour cette organisation. Réactivez un forfait pour accepter à nouveau les envois.',
                    'code' => 'subscription_inactive',
                    'logId' => $logId,
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
            );

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $snap['blockReason'] ?? 'Quota d’événements épuisé. Augmentez votre forfait ou achetez un pack.',
                    'code' => 'events_quota_exceeded',
                    'subscription' => $snap,
                    'logId' => $logId,
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
            $this->applicationErrorLogger->logThrowable($e, $request, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'form_webhook_ingress_parse',
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
                if ($action->getActionType() === ServiceIntegrationType::MAILJET) {
                    [$toEmail, $toName] = $this->recipientResolver->resolve($action, $parsed);
                    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new \InvalidArgumentException('Destinataire invalide pour une action : renseignez recipientEmailPostKey ou defaultRecipientEmail.');
                    }
                    $aLog->setToEmail($toEmail);

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

        $cfg = $sc->getConfig();
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
    private function persistIngressRejectionLog(FormWebhook $webhook, Request $request, string $errorDetail): int
    {
        $log = new FormWebhookLog();
        $log->setFormWebhook($webhook);
        $log->setClientIp($request->getClientIp());
        $log->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 512));
        $log->setContentType((string) $request->headers->get('Content-Type', ''));
        $log->setRawBody($this->truncateRaw($request->getContent()));
        $log->setStatus(FormWebhookLogStatus::ERROR);
        $log->setErrorDetail($errorDetail);
        $t0 = (int) round(microtime(true) * 1000);
        $this->persistLog($log, $t0);

        return (int) $log->getId();
    }

    private function truncateRaw(string $raw): string
    {
        if (mb_strlen($raw) <= self::RAW_BODY_MAX_LEN) {
            return $raw;
        }

        return mb_substr($raw, 0, self::RAW_BODY_MAX_LEN)."\n… [tronqué]";
    }
}
