import { z } from 'zod';
import { features } from './features';
import { integrations } from './integrations';
import { plans, eventPacks } from './pricing';
import { homeFaq, pricingFaq } from './faq';
import { useCases } from './useCases';

const statusSchema = z.enum(['available', 'beta', 'planned']);

const featureSchema = z.object({
  id: z.string().min(1),
  title: z.string().min(1),
  description: z.string().min(1),
  status: statusSchema,
  category: z.enum(['collect', 'orchestrate', 'connect', 'supervise', 'admin']),
  documentationUrl: z.string().optional(),
});

const planSchema = z.object({
  id: z.enum(['free', 'starter', 'pro']),
  name: z.string().min(1),
  priceMonthlyEur: z.number().nonnegative(),
  includedEvents: z.number().int().positive(),
  maxWebhooks: z.number().int().positive().nullable(),
  allowEventOverage: z.boolean(),
  description: z.string().min(1),
  highlights: z.array(z.string()).min(1),
  ctaLabel: z.string().min(1),
  highlighted: z.boolean().optional(),
});

/** Constantes attendues depuis SubscriptionPlanCatalog.php */
export const EXPECTED_CATALOG = {
  freeEvents: 100,
  starterEvents: 5000,
  proEvents: 50000,
  starterPrice: 9,
  proPrice: 29,
  packs: [
    { id: 'starter_1k', events: 1000, price: 2 },
    { id: 'starter_5k', events: 5000, price: 8 },
    { id: 'pro_10k', events: 10000, price: 5 },
    { id: 'pro_50k', events: 50000, price: 20 },
  ],
} as const;

export function validateProductConfig(): { ok: true } | { ok: false; errors: string[] } {
  const errors: string[] = [];

  const feat = z.array(featureSchema).safeParse(features);
  if (!feat.success) errors.push(`features: ${feat.error.message}`);

  const planParsed = z.array(planSchema).safeParse(plans);
  if (!planParsed.success) errors.push(`plans: ${planParsed.error.message}`);

  const free = plans.find((p) => p.id === 'free');
  const starter = plans.find((p) => p.id === 'starter');
  const pro = plans.find((p) => p.id === 'pro');

  if (!free || free.includedEvents !== EXPECTED_CATALOG.freeEvents || free.priceMonthlyEur !== 0) {
    errors.push('Plan Free désynchronisé du catalogue applicatif');
  }
  if (
    !starter ||
    starter.includedEvents !== EXPECTED_CATALOG.starterEvents ||
    starter.priceMonthlyEur !== EXPECTED_CATALOG.starterPrice
  ) {
    errors.push('Plan Starter désynchronisé du catalogue applicatif');
  }
  if (
    !pro ||
    pro.includedEvents !== EXPECTED_CATALOG.proEvents ||
    pro.priceMonthlyEur !== EXPECTED_CATALOG.proPrice
  ) {
    errors.push('Plan Pro désynchronisé du catalogue applicatif');
  }

  for (const expected of EXPECTED_CATALOG.packs) {
    const pack = eventPacks.find((p) => p.id === expected.id);
    if (!pack || pack.eventsAdded !== expected.events || pack.priceEur !== expected.price) {
      errors.push(`Pack ${expected.id} désynchronisé`);
    }
  }

  const ids = new Set<string>();
  for (const f of features) {
    if (ids.has(f.id)) errors.push(`Feature id dupliqué: ${f.id}`);
    ids.add(f.id);
  }

  if (integrations.length < 1) errors.push('Aucune intégration définie');
  if (useCases.length < 1) errors.push('Aucun cas d’usage défini');
  if (homeFaq.length < 1 || pricingFaq.length < 1) errors.push('FAQ incomplète');

  return errors.length ? { ok: false, errors } : { ok: true };
}
