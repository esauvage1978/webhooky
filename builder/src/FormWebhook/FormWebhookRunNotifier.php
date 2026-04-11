<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\ApplicationErrorLog;
use App\Entity\FormWebhook;
use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\Logging\ApplicationErrorLogger;
use App\Service\WebhookyPublicUrlResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * E-mail de récap d’exécution en cas d’échec au créateur ou à une adresse personnalisée.
 * Canal : POST JSON vers le webhook formulaire plateforme (variables + email / name pour le destinataire Mailjet),
 * puis repli SMTP Symfony si l’ingress échoue.
 */
final class FormWebhookRunNotifier
{
    private readonly string $publicUrl;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Lazy]
        private readonly FormWebhookIngressHandlerInterface $formWebhookIngressHandler,
        private readonly ErrorNotifyWebhookUrlResolver $errorNotifyWebhookUrlResolver,
        private readonly ErrorNotifyWebhookFromResolver $errorNotifyWebhookFromResolver,
        WebhookyPublicUrlResolver $webhookyPublicUrlResolver,
        private readonly ApplicationErrorLogger $applicationErrorLogger,
    ) {
        $this->publicUrl = rtrim($webhookyPublicUrlResolver->resolve(), '/');
    }

    public function notifyAfterRun(FormWebhook $webhook, FormWebhookLog $log, bool $success): void
    {
        if ($success && !$webhook->isNotifyOnSuccess()) {
            return;
        }
        if (!$success && !$webhook->isNotifyOnError()) {
            return;
        }

        $to = FormWebhookNotificationRecipientResolver::resolve($webhook);
        if ($to === null || $to === '') {
            return;
        }

        if (!$success && $this->shouldDispatchErrorViaFormWebhook($webhook)) {
            $payload = $this->buildErrorNotifyPayload($webhook, $log, $to);
            $token = $this->extractFormWebhookToken($this->resolveErrorNotifyWebhookUrl());
            \assert($token !== null);

            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $symfonyRequest = Request::create(
                    '/webhook/form/'.$token,
                    'POST',
                    [],
                    [],
                    [],
                    [
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_ACCEPT' => 'application/json',
                        'REMOTE_ADDR' => '127.0.0.1',
                    ],
                    $json,
                );

                $ingressResponse = $this->formWebhookIngressHandler->handle($symfonyRequest, $token);
                $status = $ingressResponse->getStatusCode();
                $body = json_decode($ingressResponse->getContent(), true);

                if ($status === 200 && \is_array($body) && ($body['ok'] ?? false) === true) {
                    return;
                }

                $this->logger->warning('Webhooky : alerte erreur via webhook formulaire — réponse non OK, repli SMTP', [
                    'status' => $status,
                    'body' => $body,
                    'errorNotifyToken' => $token,
                    'sourceWebhookId' => $webhook->getId(),
                    'logId' => $log->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->applicationErrorLogger->logThrowable($e, null, ApplicationErrorLog::SOURCE_HANDLED, [
                    'handler' => 'form_webhook_run_notifier_ingress',
                    'sourceWebhookId' => $webhook->getId(),
                    'logId' => $log->getId(),
                ]);
                $this->logger->warning('Webhooky : exception envoi alerte erreur via webhook formulaire, repli SMTP', [
                    'exception' => $e,
                    'errorNotifyToken' => $token,
                    'sourceWebhookId' => $webhook->getId(),
                    'logId' => $log->getId(),
                ]);
            }
        }

        $logId = $log->getId();
        $webhookName = $webhook->getName();
        $statusLabel = $success ? 'succès' : 'erreur';
        $subject = sprintf('[Webhooky] Workflow « %s » — exécution #%s (%s)', $webhookName, $logId ?? '?', $statusLabel);

        $lines = [];
        $lines[] = 'Récapitulatif d’exécution depuis Webhooky ('.$this->publicUrl.').';
        $lines[] = '';
        $lines[] = 'Workflow : '.$webhookName;
        $lines[] = 'Journal : #'.($logId ?? '—');
        $lines[] = 'Statut global : '.($success ? 'toutes les actions ont réussi' : 'au moins une action a échoué');
        if ($log->getErrorDetail() !== null && $log->getErrorDetail() !== '') {
            $lines[] = 'Message : '.$log->getErrorDetail();
        }
        $lines[] = '';
        foreach ($log->getActionLogs() as $i => $al) {
            $lines[] = $this->formatActionLine($i + 1, $al);
        }
        $lines[] = '';
        $lines[] = '—';
        $lines[] = 'Webhooky · ne répondez pas à cet e-mail automatisé.';

        $plain = implode("\n", $lines);
        $html = '<pre style="font-family:system-ui,sans-serif;font-size:14px;line-height:1.5">'
            .htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';

        try {
            $email = (new Email())
                ->from(new Address($this->errorNotifyWebhookFromResolver->resolve(), 'Webhooky'))
                ->to($to)
                ->subject($subject)
                ->text($plain)
                ->html($html);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->applicationErrorLogger->logThrowable($e, null, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'form_webhook_run_notifier_smtp',
                'webhookId' => $webhook->getId(),
                'logId' => $logId,
            ]);
            $this->logger->warning('Webhooky : échec envoi notification exécution', [
                'exception' => $e,
                'webhookId' => $webhook->getId(),
                'logId' => $logId,
            ]);
        }
    }

    private function shouldDispatchErrorViaFormWebhook(FormWebhook $webhook): bool
    {
        $url = $this->resolveErrorNotifyWebhookUrl();
        $token = $this->extractFormWebhookToken($url);
        if ($token === null) {
            return false;
        }

        // Éviter une boucle si le workflow en échec est le même que le webhook d’alerte.
        return $webhook->getPublicToken() !== $token;
    }

    private function resolveErrorNotifyWebhookUrl(): string
    {
        return $this->errorNotifyWebhookUrlResolver->resolve();
    }

    private function extractFormWebhookToken(string $webhookUrl): ?string
    {
        if (preg_match('#/webhook/form/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $webhookUrl, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function buildErrorNotifyPayload(FormWebhook $webhook, FormWebhookLog $log, string $notifyRecipient): array
    {
        $base = rtrim($this->publicUrl, '/');
        $failureStage = $log->getActionLogs()->isEmpty() ? 'parse' : 'actions';
        $failureStageLabel = $failureStage === 'parse'
            ? 'Erreur lors de l’analyse du corps de la requête (payload).'
            : 'Erreur lors de l’exécution d’au moins une action (ex. Mailjet).';

        $parsed = $log->getParsedInput();
        $parsedJson = '';
        if ($parsed !== null) {
            $encoded = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $parsedJson = $this->truncate($encoded !== false ? $encoded : '', 8000);
        }

        return [
            'source' => 'webhooky.builders-error-notify',
            'email' => $notifyRecipient,
            'name' => $this->errorNotifyRecipientDisplayName($webhook, $notifyRecipient),
            'workflow_name' => $webhook->getName(),
            'workflow_id' => (string) ($webhook->getId() ?? ''),
            'organization_name' => $webhook->getOrganization()?->getName() ?? '',
            'log_id' => (string) ($log->getId() ?? ''),
            'ingress_url' => $base.'/webhook/form/'.$webhook->getPublicToken(),
            'failure_stage' => $failureStage,
            'failure_stage_label' => $failureStageLabel,
            'global_error_detail' => (string) ($log->getErrorDetail() ?? ''),
            'log_status' => $log->getStatus(),
            'received_at' => $log->getReceivedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            'duration_ms' => $log->getDurationMs() !== null ? (string) $log->getDurationMs() : '',
            'client_ip' => (string) ($log->getClientIp() ?? ''),
            'user_agent' => $this->truncate((string) ($log->getUserAgent() ?? ''), 500),
            'content_type' => (string) ($log->getContentType() ?? ''),
            'raw_body_preview' => $this->truncate((string) ($log->getRawBody() ?? ''), 3500),
            'parsed_input_json' => $parsedJson,
            'actions_detail' => $this->buildActionsDetail($log),
            'notify_recipient' => $notifyRecipient,
            'public_app_url' => $base,
        ];
    }

    private function errorNotifyRecipientDisplayName(FormWebhook $webhook, string $notifyEmail): string
    {
        $org = $webhook->getOrganization();
        if ($org !== null && trim($org->getName()) !== '') {
            return trim($org->getName());
        }

        $local = strstr($notifyEmail, '@', true);

        return $local !== false && $local !== '' ? $local : $webhook->getName();
    }

    private function buildActionsDetail(FormWebhookLog $log): string
    {
        if ($log->getActionLogs()->isEmpty()) {
            return 'Aucune action exécutée (échec avant ou pendant la phase d’enchaînement).';
        }

        $parts = [];
        foreach ($log->getActionLogs() as $i => $al) {
            $parts[] = '#'.($i + 1).' '.$this->formatActionLine($i + 1, $al);
        }

        return implode(' · ', $parts);
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max).'…';
    }

    private function formatActionLine(int $n, FormWebhookActionLog $al): string
    {
        $parts = ['Action '.$n];
        $parts[] = 'statut : '.$al->getStatus();
        if ($al->getToEmail() !== null && $al->getToEmail() !== '') {
            $parts[] = 'à : '.$al->getToEmail();
        }
        if ($al->getMailjetHttpStatus() !== null) {
            $parts[] = 'HTTP '.$al->getMailjetHttpStatus();
        }
        if ($al->getMailjetMessageId() !== null && $al->getMailjetMessageId() !== '') {
            $parts[] = 'message Mailjet : '.$al->getMailjetMessageId();
        }
        if ($al->getErrorDetail() !== null && $al->getErrorDetail() !== '') {
            $parts[] = 'erreur : '.$al->getErrorDetail();
        }

        return implode(' · ', $parts);
    }
}
