<?php

declare(strict_types=1);

namespace App\Mailjet;

/**
 * Clés API depuis un connecteur générique (JSON), sans entité {@see \App\Entity\Mailjet}.
 */
final class MailjetApiKeyPair implements MailjetAuthPairInterface
{
    public function __construct(
        private readonly string $apiKeyPublic,
        private readonly string $apiKeyPrivate,
    ) {
    }

    public function getApiKeyPublic(): string
    {
        return $this->apiKeyPublic;
    }

    public function getApiKeyPrivate(): string
    {
        return $this->apiKeyPrivate;
    }
}
