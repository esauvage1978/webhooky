/** Statut produit affiché sur la vitrine — source unique pour éviter les incohérences. */
export type FeatureStatus = 'available' | 'beta' | 'planned';

export type FeatureCategory =
  | 'collect'
  | 'orchestrate'
  | 'connect'
  | 'supervise'
  | 'admin';

export type IntegrationCategory =
  | 'email'
  | 'sms'
  | 'messaging'
  | 'http'
  | 'notification'
  | 'other';

export interface ProductFeature {
  id: string;
  title: string;
  description: string;
  status: FeatureStatus;
  category: FeatureCategory;
  documentationUrl?: string;
}

export interface Integration {
  id: string;
  name: string;
  category: IntegrationCategory;
  status: FeatureStatus;
  description: string;
  actions: string[];
  prerequisites: string[];
  documentationUrl?: string;
  detailUrl?: string;
  vendorUrl?: string;
}

export interface PricingPlan {
  id: 'free' | 'starter' | 'pro';
  name: string;
  priceMonthlyEur: number;
  includedEvents: number;
  /** null = non plafonné dans le forfait (usage raisonnable + protections techniques). */
  maxWebhooks: number | null;
  allowEventOverage: boolean;
  description: string;
  highlights: string[];
  ctaLabel: string;
  highlighted?: boolean;
}

export interface EventPack {
  id: string;
  forPlan: 'starter' | 'pro';
  eventsAdded: number;
  priceEur: number;
  label: string;
  /** Achat self-serve Stripe pas encore disponible côté application. */
  selfServeAvailable: boolean;
}

export interface NavItem {
  href: string;
  label: string;
  children?: NavItem[];
}

export interface FaqItem {
  id: string;
  question: string;
  answer: string;
}
