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

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::RECEIVED, self::PARSED, self::SENT, self::ERROR, self::SKIPPED];
    }
}
