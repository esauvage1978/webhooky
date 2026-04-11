<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Repository\OptionRepository;

/**
 * Origines CORS pour l’ingress formulaire : option plateforme {@see self::OPTION_NAME} (liste séparée par « ; »).
 */
final class FormWebhookCorsOriginsResolver
{
    public const OPTION_NAME = 'webhooky_webhook_cors_origins';

    /**
     * Repli si l’option est absente ou vide (avant premier `app:ensure-default-platform-options` ou base vide).
     *
     * @var list<string>
     */
    private const FALLBACK_ORIGINS = [
        'https://webhooky.fr',
        'https://www.webhooky.fr',
        'http://localhost:4321',
        'http://127.0.0.1:4321',
    ];

    public function __construct(
        private readonly OptionRepository $optionRepository,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolve(): array
    {
        $opt = $this->optionRepository->findFirstByOptionName(self::OPTION_NAME);
        if ($opt === null) {
            return self::FALLBACK_ORIGINS;
        }

        $raw = trim($opt->getOptionValue());
        if ($raw === '') {
            return self::FALLBACK_ORIGINS;
        }

        return $this->parseSemicolonList($raw);
    }

    /**
     * @return list<string>
     */
    private function parseSemicolonList(string $raw): array
    {
        $out = [];
        foreach (explode(';', $raw) as $chunk) {
            $o = trim($chunk);
            if ($o !== '') {
                $out[] = $o;
            }
        }

        return $out !== [] ? $out : self::FALLBACK_ORIGINS;
    }
}
