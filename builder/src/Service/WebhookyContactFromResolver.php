<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OptionRepository;

/**
 * Adresse d’expéditeur « contact » (référence documentaire / futurs usages) : {@see self::OPTION_NAME}.
 */
final class WebhookyContactFromResolver
{
    public const OPTION_NAME = 'webhooky_contact_from';

    private const FALLBACK = 'contact@webhooky.builders';

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
