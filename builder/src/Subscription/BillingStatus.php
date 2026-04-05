<?php

declare(strict_types=1);

namespace App\Subscription;

/**
 * États alignés sur un prestataire type Stripe (customer.subscription.status).
 */
enum BillingStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Période d’essai facturation',
            self::Active => 'Actif',
            self::PastDue => 'Paiement en retard',
            self::Canceled => 'Résilié',
            self::Incomplete => 'Paiement incomplet',
        };
    }
}
