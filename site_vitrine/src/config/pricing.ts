/**
 * Grille tarifaire — miroir de builder/src/Subscription/SubscriptionPlanCatalog.php
 * Toute modification doit être répercutée des deux côtés (test de sync inclus).
 */
import type { EventPack, PricingPlan } from './types';

export const PRICING_NOTE_FR =
  'Montants hors taxes (HT). La TVA applicable sera ajoutée à la facturation.';

/** 1 événement = 1 action exécutée dans le workflow (confirmé côté application). */
export const EVENT_DEFINITION_FR =
  'Un événement correspond à une action exécutée dans un scénario (par exemple un envoi Mailjet ou un appel HTTP). Si un webhook déclenche trois actions, trois événements sont comptabilisés.';

export const QUOTA_EXCEEDED_FR = {
  free: 'Lorsque le quota Free est atteint, les nouvelles exécutions sont refusées (HTTP 402). Passez à Starter ou Pro pour continuer.',
  paid: 'Lorsque le quota d’événements est atteint, les exécutions sont refusées jusqu’à l’achat d’un pack ou le renouvellement de la période. Il n’y a pas de dépassement facturé automatiquement.',
} as const;

export const plans: PricingPlan[] = [
  {
    id: 'free',
    name: 'Free',
    priceMonthlyEur: 0,
    includedEvents: 100,
    maxWebhooks: 1,
    allowEventOverage: false,
    description: 'Pour découvrir le produit et tester vos premiers scénarios.',
    highlights: [
      '100 événements par période',
      '1 webhook',
      'Connecteurs disponibles',
      'Journaux d’exécution',
      'Pas de dépassement automatique',
    ],
    ctaLabel: 'Créer un compte gratuit',
  },
  {
    id: 'starter',
    name: 'Starter',
    priceMonthlyEur: 9,
    includedEvents: 5000,
    maxWebhooks: null,
    allowEventOverage: true,
    description: 'Pour les sites, freelances et petites équipes en production.',
    highlights: [
      '5 000 événements inclus',
      'Webhooks non plafonnés dans le forfait*',
      'Organisations et membres',
      'Packs d’événements en catalogue',
      'Support standard',
    ],
    ctaLabel: 'Choisir Starter',
    highlighted: true,
  },
  {
    id: 'pro',
    name: 'Pro',
    priceMonthlyEur: 29,
    includedEvents: 50000,
    maxWebhooks: null,
    allowEventOverage: true,
    description: 'Pour les volumes plus élevés et les usages multi-projets.',
    highlights: [
      '50 000 événements inclus',
      'Webhooks non plafonnés dans le forfait*',
      'Packs dégressifs en catalogue',
      'Supervision et historiques étendus',
      'Usage multi-organisations',
    ],
    ctaLabel: 'Choisir Pro',
  },
];

/**
 * Packs catalogue — l’achat self-serve Stripe n’est pas encore disponible
 * pour les gestionnaires (message applicatif « prochainement »).
 */
export const eventPacks: EventPack[] = [
  {
    id: 'starter_1k',
    forPlan: 'starter',
    eventsAdded: 1000,
    priceEur: 2,
    label: '+1 000 événements (Starter)',
    selfServeAvailable: false,
  },
  {
    id: 'starter_5k',
    forPlan: 'starter',
    eventsAdded: 5000,
    priceEur: 8,
    label: '+5 000 événements (Starter)',
    selfServeAvailable: false,
  },
  {
    id: 'pro_10k',
    forPlan: 'pro',
    eventsAdded: 10000,
    priceEur: 5,
    label: '+10 000 événements (Pro)',
    selfServeAvailable: false,
  },
  {
    id: 'pro_50k',
    forPlan: 'pro',
    eventsAdded: 50000,
    priceEur: 20,
    label: '+50 000 événements (Pro)',
    selfServeAvailable: false,
  },
];

export const comparisonRows = [
  { key: 'price', label: 'Prix mensuel HT' },
  { key: 'events', label: 'Événements inclus' },
  { key: 'webhooks', label: 'Webhooks' },
  { key: 'orgs', label: 'Organisations' },
  { key: 'users', label: 'Utilisateurs' },
  { key: 'actions', label: 'Actions par scénario' },
  { key: 'history', label: 'Journaux d’exécution' },
  { key: 'replay', label: 'Rejeu d’événements' },
  { key: 'overage', label: 'Packs au-delà du quota' },
  { key: 'support', label: 'Support' },
  { key: 'api', label: 'API publique documentée' },
  { key: 'retention', label: 'Durée de conservation des logs' },
] as const;

export function formatPriceEur(amount: number): string {
  if (amount === 0) return '0 €';
  return `${amount.toLocaleString('fr-FR')} €`;
}

export function formatWebhooks(max: number | null): string {
  if (max === null) return 'Non plafonnés*';
  return String(max);
}
