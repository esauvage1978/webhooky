<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Remplace {{cle}} dans un modèle par les valeurs du mapping puis, à défaut, par le payload plat.
 */
final class IntegrationPayloadInterpolator
{
    /**
     * @param array<string, string> $variables   résultat VariableMapBuilder
     * @param array<string, string> $flatInput   champs du formulaire / JSON aplati
     */
    public function interpolate(?string $template, array $variables, array $flatInput): string
    {
        $t = $template ?? '';

        return (string) preg_replace_callback('/\{\{([\w.\-]+)\}\}/', function (array $m) use ($variables, $flatInput): string {
            $k = $m[1];
            if (\array_key_exists($k, $variables) && $variables[$k] !== '') {
                return $variables[$k];
            }
            if (\array_key_exists($k, $flatInput)) {
                return $flatInput[$k];
            }

            return '';
        }, $t);
    }
}
