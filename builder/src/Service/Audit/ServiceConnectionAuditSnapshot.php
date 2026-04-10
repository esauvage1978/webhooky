<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\ServiceConnection;

/**
 * Empreinte sans secrets pour détecter les changements de connecteur.
 */
final class ServiceConnectionAuditSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromConnection(ServiceConnection $s): array
    {
        $config = $s->getConfig() ?? [];

        return [
            'type' => $s->getType(),
            'name' => $s->getName(),
            'organizationId' => $s->getOrganization()?->getId(),
            'configFingerprint' => hash('sha256', json_encode(self::normalizeConfig($config)) ?: '{}'),
        ];
    }

    /**
     * Normalise pour comparaison (clés triées, valeurs scalaires uniquement dans l’empreinte).
     *
     * @param array<string, mixed|false|null> $config
     *
     * @return array<string, mixed>
     */
    private static function normalizeConfig(array $config): array
    {
        ksort($config);
        $out = [];
        foreach ($config as $k => $v) {
            if (\is_array($v)) {
                $out[(string) $k] = self::normalizeConfig($v);

                continue;
            }
            if (\is_string($v)) {
                $out[(string) $k] = 'len:'.mb_strlen($v);

                continue;
            }
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
