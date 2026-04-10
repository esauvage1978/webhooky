<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Repository\OptionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * URL du webhook formulaire pour les alertes d’erreur d’exécution :
 * option plateforme {@see self::OPTION_NAME}, sinon paramètre app.webhooky.error_notify_webhook_url.
 */
final class ErrorNotifyWebhookUrlResolver
{
    public const OPTION_NAME = 'WEBHOOKY_ERROR_NOTIFY_WEBHOOK_URL';

    public function __construct(
        private readonly OptionRepository $optionRepository,
        #[Autowire('%app.webhooky.error_notify_webhook_url%')]
        private readonly string $defaultUrl,
    ) {
    }

    public function resolve(): string
    {
        $opt = $this->optionRepository->findFirstByOptionName(self::OPTION_NAME);
        if ($opt !== null) {
            $v = trim($opt->getOptionValue());
            if ($v !== '') {
                return $v;
            }
        }

        return trim($this->defaultUrl);
    }
}
