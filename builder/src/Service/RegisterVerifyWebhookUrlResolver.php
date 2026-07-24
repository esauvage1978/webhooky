<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Webhook formulaire confirmation d’inscription (repli si `WEBHOOKY_REGISTER_VERIFY_WEBHOOK_URL` vide) : {@see self::OPTION_NAME}.
 */
final class RegisterVerifyWebhookUrlResolver
{
    public const OPTION_NAME = 'webhooky_register_verify_webhook_url';

    private const FALLBACK = 'https://webhooky.builders/webhook/form/c6b74536-bac5-4442-b250-a9d3b1085002';

    public function __construct(
        private readonly PlatformOptionStringResolver $optionStringResolver,
    ) {
    }

    public function resolve(): string
    {
        return $this->optionStringResolver->resolve(self::OPTION_NAME, self::FALLBACK);
    }
}
