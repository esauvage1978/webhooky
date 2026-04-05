<?php

declare(strict_types=1);

namespace App\FormWebhook\PayloadParser;

use Symfony\Component\HttpFoundation\Request;

/**
 * Extrait un tableau plat chaîne → chaîne depuis le corps de la requête HTTP.
 */
interface PayloadParserInterface
{
    /**
     * Priorité décroissante : le premier parser dont supports() est vrai gagne.
     */
    public function getPriority(): int;

    public function supports(Request $request): bool;

    /**
     * @return array<string, string> valeurs normalisées en chaînes (scalars castés)
     */
    public function parse(Request $request): array;
}
