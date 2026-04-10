<?php

declare(strict_types=1);

namespace App\Subscription;

enum SubscriptionPlan: string
{
    /** 0 € — 100 événements inclus, aucun dépassement. */
    case Free = 'free';
    /** 9 € HT/mois — 5 000 événements + packs extension. */
    case Starter = 'starter';
    /** 29 € HT/mois — 50 000 événements + packs extension (prix dégressifs). */
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free (0 €)',
            self::Starter => 'Starter (9 € HT/mois)',
            self::Pro => 'Pro (29 € HT/mois)',
        };
    }

    /**
     * Limite de webhooks formulaire : Free = 1, offres payantes = illimité.
     */
    public function maxWebhooks(): ?int
    {
        return match ($this) {
            self::Free => 1,
            self::Starter, self::Pro => null,
        };
    }

    /** Quota inclus dans l’abonnement (hors achats d’extension stockés sur l’organisation). */
    public function baseEventsIncluded(): int
    {
        return match ($this) {
            self::Free => SubscriptionPlanCatalog::FREE_INCLUDED_EVENTS,
            self::Starter => SubscriptionPlanCatalog::STARTER_INCLUDED_EVENTS,
            self::Pro => SubscriptionPlanCatalog::PRO_INCLUDED_EVENTS,
        };
    }
}
