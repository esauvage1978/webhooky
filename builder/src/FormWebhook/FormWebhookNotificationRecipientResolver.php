<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhook;

/**
 * Destinataire des e-mails de récap d’erreur configurés sur un workflow.
 */
final class FormWebhookNotificationRecipientResolver
{
    public static function resolve(FormWebhook $webhook): ?string
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

    public static function explainMissingRecipient(FormWebhook $webhook): string
    {
        if ($webhook->getNotificationEmailSource() === FormWebhook::NOTIFICATION_EMAIL_CUSTOM) {
            return 'Adresse personnalisée absente ou invalide.';
        }
        if ($webhook->getCreatedBy() === null) {
            return 'Aucun créateur associé au workflow (compte supprimé ou donnée manquante).';
        }

        return 'E-mail du créateur invalide.';
    }
}

