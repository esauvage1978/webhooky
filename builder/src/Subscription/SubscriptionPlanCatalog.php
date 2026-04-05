<?php

declare(strict_types=1);

namespace App\Subscription;

/**
 * Grille commerciale + packs d’événements (montants indicatifs — facturation réelle via Stripe).
 */
final class SubscriptionPlanCatalog
{
    public const FREE_INCLUDED_EVENTS = 100;

    public const STARTER_INCLUDED_EVENTS = 5000;

    public const PRO_INCLUDED_EVENTS = 50000;

    public const PRICE_STARTER_EUR = 9.0;

    public const PRICE_PRO_EUR = 29.0;

    /** Packs Starter : +1 000 → 2 €, +5 000 → 8 €. */
    public const STARTER_PACK_1K_ID = 'starter_1k';

    public const STARTER_PACK_1K_EVENTS = 1000;

    public const STARTER_PACK_1K_PRICE_EUR = 2.0;

    public const STARTER_PACK_5K_ID = 'starter_5k';

    public const STARTER_PACK_5K_EVENTS = 5000;

    public const STARTER_PACK_5K_PRICE_EUR = 8.0;

    /** Packs Pro : +10 000 → 5 €, +50 000 → 20 €. */
    public const PRO_PACK_10K_ID = 'pro_10k';

    public const PRO_PACK_10K_EVENTS = 10000;

    public const PRO_PACK_10K_PRICE_EUR = 5.0;

    public const PRO_PACK_50K_ID = 'pro_50k';

    public const PRO_PACK_50K_EVENTS = 50000;

    public const PRO_PACK_50K_PRICE_EUR = 20.0;

    /**
     * @return list<array<string, mixed>>
     */
    public static function allPlans(): array
    {
        return [
            [
                'id' => SubscriptionPlan::Free->value,
                'label' => SubscriptionPlan::Free->label(),
                'maxWebhooks' => 1,
                'includedEvents' => self::FREE_INCLUDED_EVENTS,
                'allowEventOverage' => false,
                'priceMonthlyEur' => 0.0,
                'description' => '100 événements inclus, pas de dépassement — passage à une offre payante obligatoire au-delà.',
            ],
            [
                'id' => SubscriptionPlan::Starter->value,
                'label' => SubscriptionPlan::Starter->label(),
                'maxWebhooks' => null,
                'includedEvents' => self::STARTER_INCLUDED_EVENTS,
                'allowEventOverage' => true,
                'priceMonthlyEur' => self::PRICE_STARTER_EUR,
                'description' => '5 000 événements inclus (~0,0018 €/év.) — extensions volontaires.',
            ],
            [
                'id' => SubscriptionPlan::Pro->value,
                'label' => SubscriptionPlan::Pro->label(),
                'maxWebhooks' => null,
                'includedEvents' => self::PRO_INCLUDED_EVENTS,
                'allowEventOverage' => true,
                'priceMonthlyEur' => self::PRICE_PRO_EUR,
                'description' => '50 000 événements inclus (~0,00058 €/év.) — packs dégressifs au-delà.',
            ],
        ];
    }

    /**
     * Packs achetables (simulation API ; Stripe metered / checkout à brancher).
     *
     * @return list<array<string, mixed>>
     */
    public static function eventPacks(): array
    {
        return [
            [
                'id' => self::STARTER_PACK_1K_ID,
                'forPlan' => SubscriptionPlan::Starter->value,
                'eventsAdded' => self::STARTER_PACK_1K_EVENTS,
                'priceEur' => self::STARTER_PACK_1K_PRICE_EUR,
                'label' => '+1 000 événements (Starter)',
            ],
            [
                'id' => self::STARTER_PACK_5K_ID,
                'forPlan' => SubscriptionPlan::Starter->value,
                'eventsAdded' => self::STARTER_PACK_5K_EVENTS,
                'priceEur' => self::STARTER_PACK_5K_PRICE_EUR,
                'label' => '+5 000 événements (Starter)',
            ],
            [
                'id' => self::PRO_PACK_10K_ID,
                'forPlan' => SubscriptionPlan::Pro->value,
                'eventsAdded' => self::PRO_PACK_10K_EVENTS,
                'priceEur' => self::PRO_PACK_10K_PRICE_EUR,
                'label' => '+10 000 événements (Pro)',
            ],
            [
                'id' => self::PRO_PACK_50K_ID,
                'forPlan' => SubscriptionPlan::Pro->value,
                'eventsAdded' => self::PRO_PACK_50K_EVENTS,
                'priceEur' => self::PRO_PACK_50K_PRICE_EUR,
                'label' => '+50 000 événements (Pro)',
            ],
        ];
    }

    /**
     * @return array{eventsAdded: int, priceEur: float, forPlan: SubscriptionPlan}|null
     */
    public static function resolveEventPack(string $packId): ?array
    {
        return match ($packId) {
            self::STARTER_PACK_1K_ID => [
                'eventsAdded' => self::STARTER_PACK_1K_EVENTS,
                'priceEur' => self::STARTER_PACK_1K_PRICE_EUR,
                'forPlan' => SubscriptionPlan::Starter,
            ],
            self::STARTER_PACK_5K_ID => [
                'eventsAdded' => self::STARTER_PACK_5K_EVENTS,
                'priceEur' => self::STARTER_PACK_5K_PRICE_EUR,
                'forPlan' => SubscriptionPlan::Starter,
            ],
            self::PRO_PACK_10K_ID => [
                'eventsAdded' => self::PRO_PACK_10K_EVENTS,
                'priceEur' => self::PRO_PACK_10K_PRICE_EUR,
                'forPlan' => SubscriptionPlan::Pro,
            ],
            self::PRO_PACK_50K_ID => [
                'eventsAdded' => self::PRO_PACK_50K_EVENTS,
                'priceEur' => self::PRO_PACK_50K_PRICE_EUR,
                'forPlan' => SubscriptionPlan::Pro,
            ],
            default => null,
        };
    }
}
