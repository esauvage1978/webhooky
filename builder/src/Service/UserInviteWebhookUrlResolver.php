<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Webhook formulaire invitation utilisateur (repli si `WEBHOOKY_USER_INVITE_WEBHOOK_URL` vide) : {@see self::OPTION_NAME}.
 */
final class UserInviteWebhookUrlResolver
{
    public const OPTION_NAME = 'webhooky_user_invite_webhook_url';

    private const FALLBACK = 'https://webhooky.builders/webhook/form/8a5aed88-22ac-4c00-955f-357410595f1b';

    public function __construct(
        private readonly PlatformOptionStringResolver $optionStringResolver,
    ) {
    }

    public function resolve(): string
    {
        return $this->optionStringResolver->resolve(self::OPTION_NAME, self::FALLBACK);
    }
}
