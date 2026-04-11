<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OptionRepository;

/**
 * Webhook formulaire confirmation d’inscription (repli si `WEBHOOKY_REGISTER_VERIFY_WEBHOOK_URL` vide) : {@see self::OPTION_NAME}.
 */
final class RegisterVerifyWebhookUrlResolver
{
    public const OPTION_NAME = 'webhooky_register_verify_webhook_url';

    private const FALLBACK = 'https://webhooky.builders/webhook/form/c6b74536-bac5-4442-b250-a9d3b1085002';

    public function __construct(
        private readonly OptionRepository $optionRepository,
    ) {
    }

    public function resolve(): string
    {
        $opt = $this->optionRepository->findFirstByOptionName(self::OPTION_NAME);
        if ($opt === null) {
            return self::FALLBACK;
        }

        $v = trim($opt->getOptionValue());

        return $v !== '' ? $v : self::FALLBACK;
    }
}
