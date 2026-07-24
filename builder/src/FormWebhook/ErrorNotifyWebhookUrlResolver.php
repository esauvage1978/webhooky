<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Service\PlatformOptionStringResolver;

/**
 * URL du webhook formulaire pour les alertes d’erreur d’exécution : option plateforme seule ({@see self::OPTION_NAME}).
 */
final class ErrorNotifyWebhookUrlResolver
{
    public const OPTION_NAME = 'WEBHOOKY_ERROR_NOTIFY_WEBHOOK_URL';

    public function __construct(
        private readonly PlatformOptionStringResolver $optionStringResolver,
    ) {
    }

    public function resolve(): string
    {
        return $this->optionStringResolver->resolve(self::OPTION_NAME, '');
    }
}
