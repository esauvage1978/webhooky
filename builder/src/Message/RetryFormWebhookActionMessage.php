<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Rejoue une action en échec hors du chemin ingress (transport async Doctrine).
 */
final class RetryFormWebhookActionMessage
{
    public function __construct(
        public readonly int $actionLogId,
        public readonly int $attempt,
    ) {
    }
}
