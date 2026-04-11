<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Repository\OptionRepository;

/**
 * Adresse expéditrice (repli SMTP) pour les alertes d’erreur d’exécution : option plateforme {@see self::OPTION_NAME}.
 */
final class ErrorNotifyWebhookFromResolver
{
    public const OPTION_NAME = 'webhooky_error_notify_webhook_from';

    /** Repli si l’option est absente ou vide (comportement historique, ancien services.yaml). */
    private const FALLBACK_EMAIL = 'notification@webhooky.builders';

    public function __construct(
        private readonly OptionRepository $optionRepository,
    ) {
    }

    public function resolve(): string
    {
        $opt = $this->optionRepository->findFirstByOptionName(self::OPTION_NAME);
        if ($opt === null) {
            return self::FALLBACK_EMAIL;
        }

        $v = trim($opt->getOptionValue());

        return $v !== '' ? $v : self::FALLBACK_EMAIL;
    }
}
