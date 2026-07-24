<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OptionRepository;

/**
 * Lecture d’une option plateforme string avec repli (évite 4 résolveurs copy-paste).
 */
final class PlatformOptionStringResolver
{
    public function __construct(
        private readonly OptionRepository $optionRepository,
    ) {
    }

    public function resolve(string $optionName, string $fallback = ''): string
    {
        $opt = $this->optionRepository->findFirstByOptionName($optionName);
        if ($opt === null) {
            return $fallback;
        }

        $v = trim($opt->getOptionValue());

        return $v !== '' ? $v : $fallback;
    }
}
