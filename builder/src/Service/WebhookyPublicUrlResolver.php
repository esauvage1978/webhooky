<?php

declare(strict_types=1);

namespace App\Service;

/**
 * URL publique canonique (liens e-mails, récaps) : option plateforme {@see self::OPTION_NAME}.
 */
final class WebhookyPublicUrlResolver
{
    public const OPTION_NAME = 'webhooky_public_url';

    private const FALLBACK = 'https://webhooky.builders';

    public function __construct(
        private readonly PlatformOptionStringResolver $optionStringResolver,
    ) {
    }

    public function resolve(): string
    {
        return $this->optionStringResolver->resolve(self::OPTION_NAME, self::FALLBACK);
    }
}
