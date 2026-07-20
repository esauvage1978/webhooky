import { describe, expect, it } from 'vitest';
import { EXPECTED_CATALOG, validateProductConfig } from './validate';
import { plans, eventPacks } from './pricing';
import { features } from './features';
import { integrations } from './integrations';

describe('product config', () => {
  it('valide la configuration globale', () => {
    const result = validateProductConfig();
    expect(result).toEqual({ ok: true });
  });

  it('reste synchronisée avec SubscriptionPlanCatalog.php', () => {
    expect(plans.find((p) => p.id === 'free')?.includedEvents).toBe(EXPECTED_CATALOG.freeEvents);
    expect(plans.find((p) => p.id === 'starter')?.priceMonthlyEur).toBe(EXPECTED_CATALOG.starterPrice);
    expect(plans.find((p) => p.id === 'pro')?.includedEvents).toBe(EXPECTED_CATALOG.proEvents);
    expect(eventPacks).toHaveLength(EXPECTED_CATALOG.packs.length);
  });

  it('expose des fonctionnalités avec statuts valides', () => {
    for (const f of features) {
      expect(['available', 'beta', 'planned']).toContain(f.status);
    }
  });

  it('liste Mailjet comme intégration disponible', () => {
    const mailjet = integrations.find((i) => i.id === 'mailjet');
    expect(mailjet?.status).toBe('available');
  });
});
