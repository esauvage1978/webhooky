<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Construit le tableau « Variables » Mailjet à partir du mapping configuré.
 *
 * Format stocké en JSON : { "nom_variable_mailjet": "cle_champ_formulaire", ... }
 */
final class VariableMapBuilder
{
    /**
     * @param array<string, string>     $flatInput   champs normalisés du POST/JSON
     * @param array<string, string>     $variableMap clé = variable Mailjet, valeur = clé source dans $flatInput
     * @return array<string, string>
     */
    public function build(array $flatInput, array $variableMap): array
    {
        $variables = [];

        foreach ($variableMap as $mailjetKey => $sourceKey) {
            $mailjetKey = trim((string) $mailjetKey);
            $sourceKey = trim((string) $sourceKey);
            if ($mailjetKey === '' || $sourceKey === '') {
                continue;
            }
            if (!isset($flatInput[$sourceKey])) {
                $variables[$mailjetKey] = '';

                continue;
            }
            $variables[$mailjetKey] = $flatInput[$sourceKey];
        }

        return $variables;
    }
}
