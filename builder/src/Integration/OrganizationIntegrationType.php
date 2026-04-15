<?php

declare(strict_types=1);

namespace App\Integration;

final class OrganizationIntegrationType
{
    public const GSC = 'gsc';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::GSC];
    }

    public static function isKnown(string $type): bool
    {
        return \in_array($type, self::all(), true);
    }
}
