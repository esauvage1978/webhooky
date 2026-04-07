<?php

declare(strict_types=1);

namespace App\Onboarding;

/**
 * Avatars prédéfinis (icône / couleur côté UI, pas d’upload fichier).
 */
final class ProfileAvatarCatalog
{
    public const KEYS = ['fox', 'panda', 'eagle', 'whale', 'leaf', 'bolt', 'moon', 'ruby'];

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function all(): array
    {
        $labels = [
            'fox' => 'Renard',
            'panda' => 'Panda',
            'eagle' => 'Aigle',
            'whale' => 'Baleine',
            'leaf' => 'Feuille',
            'bolt' => 'Éclair',
            'moon' => 'Lune',
            'ruby' => 'Rubis',
        ];

        $out = [];
        foreach (self::KEYS as $k) {
            $out[] = ['id' => $k, 'label' => $labels[$k] ?? $k];
        }

        return $out;
    }

    public static function isAllowed(string $key): bool
    {
        return \in_array($key, self::KEYS, true);
    }
}
