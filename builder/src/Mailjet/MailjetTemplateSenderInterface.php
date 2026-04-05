<?php

declare(strict_types=1);

namespace App\Mailjet;

use App\Entity\Mailjet;

/**
 * Envoi d’un message basé sur un template Mailjet (API v3.1).
 */
interface MailjetTemplateSenderInterface
{
    /**
     * @param array<string, string> $variables variables du template
     */
    public function sendTemplate(
        Mailjet $mailjetConfig,
        int $templateId,
        bool $templateLanguage,
        string $toEmail,
        ?string $toName,
        array $variables,
    ): MailjetTemplateSendResult;
}
