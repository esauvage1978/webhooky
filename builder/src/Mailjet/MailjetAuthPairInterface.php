<?php

declare(strict_types=1);

namespace App\Mailjet;

/**
 * Paire de clés API Mailjet pour l’envoi (entité {@see \App\Entity\Mailjet} ou config service_connection).
 */
interface MailjetAuthPairInterface
{
    public function getApiKeyPublic(): string;

    public function getApiKeyPrivate(): string;
}
