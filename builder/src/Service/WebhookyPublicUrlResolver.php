<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OptionRepository;

/**
 * URL publique canonique (liens e-mails, récaps) : option plateforme {@see self::OPTION_NAME}.
 */
final class WebhookyPublicUrlResolver
{
    public const OPTION_NAME = 'webhooky_public_url';

    private const FALLBACK = 'https://webhooky.builders';

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
