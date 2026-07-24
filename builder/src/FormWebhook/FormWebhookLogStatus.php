<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Statuts de traçage (extensible sans casser l’API).
 */
final class FormWebhookLogStatus
{
    public const RECEIVED = 'received';

    public const PARSED = 'parsed';

    public const SENT = 'sent';

    public const ERROR = 'error';

    /** Réception traitée / parsée mais aucune action exécutée (ex. workflow désactivé). */
    public const SKIPPED = 'skipped';

    /** Au moins une action en échec a été replanifiée (Messenger). */
    public const RETRY_SCHEDULED = 'retry_scheduled';

    /** Échecs définitifs après épuisement des tentatives. */
    public const DEAD_LETTER = 'dead_letter';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::RECEIVED,
            self::PARSED,
            self::SENT,
            self::ERROR,
            self::SKIPPED,
            self::RETRY_SCHEDULED,
            self::DEAD_LETTER,
        ];
    }

    public static function isFailure(string $status): bool
    {
        return \in_array($status, [self::ERROR, self::DEAD_LETTER], true);
    }

    public static function isTerminalSuccess(string $status): bool
    {
        return $status === self::SENT || $status === self::SKIPPED;
    }
}
