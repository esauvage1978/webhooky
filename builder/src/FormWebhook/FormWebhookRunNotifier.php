<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * E-mail de récap d’exécution (Webhooky / contact@Webhooky.fr).
 */
final class FormWebhookRunNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.webhooky.contact_from%')]
        private readonly string $fromEmail,
        #[Autowire('%app.webhooky.public_url%')]
        private readonly string $publicUrl,
    ) {
    }

    public function notifyAfterRun(FormWebhook $webhook, FormWebhookLog $log, bool $success): void
    {
        if ($success && !$webhook->isNotifyOnSuccess()) {
            return;
        }
        if (!$success && !$webhook->isNotifyOnError()) {
            return;
        }

        $to = $this->resolveRecipient($webhook);
        if ($to === null || $to === '') {
            return;
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
                ->from(new Address($this->fromEmail, 'Webhooky'))
                ->to($to)
                ->subject($subject)
                ->text($plain)
                ->html($html);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhooky : échec envoi notification exécution', [
                'exception' => $e,
                'webhookId' => $webhook->getId(),
                'logId' => $logId,
            ]);
        }
    }

    private function resolveRecipient(FormWebhook $webhook): ?string
    {
        if ($webhook->getNotificationEmailSource() === FormWebhook::NOTIFICATION_EMAIL_CUSTOM) {
            $e = $webhook->getNotificationCustomEmail();
            if ($e !== null && filter_var(trim($e), FILTER_VALIDATE_EMAIL)) {
                return trim($e);
            }

            return null;
        }

        $creator = $webhook->getCreatedBy();
        if ($creator === null) {
            return null;
        }
        $mail = $creator->getEmail();

        return filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
    }

    private function formatActionLine(int $n, FormWebhookActionLog $al): string
    {
        $parts = ['Action '.$n];
        $parts[] = 'statut : '.$al->getStatus();
        if ($al->getToEmail() !== null && $al->getToEmail() !== '') {
            $parts[] = 'à : '.$al->getToEmail();
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
