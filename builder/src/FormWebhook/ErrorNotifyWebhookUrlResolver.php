<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Repository\OptionRepository;

/**
 * URL du webhook formulaire pour les alertes d’erreur d’exécution : option plateforme seule ({@see self::OPTION_NAME}).
 */
final class ErrorNotifyWebhookUrlResolver
{
    public const OPTION_NAME = 'WEBHOOKY_ERROR_NOTIFY_WEBHOOK_URL';

    public function __construct(
        private readonly OptionRepository $optionRepository,
    ) {
    }

    public function resolve(): string
    {
        $opt = $this->optionRepository->findFirstByOptionName(self::OPTION_NAME);
        if ($opt === null) {
            return '';
        }

        return trim($opt->getOptionValue());
    }
}
