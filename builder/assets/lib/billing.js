import { parseJson } from './http.js';
import { safeStripeRedirectUrl } from './urlSecurity.js';

/**
 * @returns {Promise<{ stripeEnabled: boolean, publishableKey: string|null }>}
 */
export async function fetchBillingConfig() {
  const res = await fetch('/api/billing/config', { credentials: 'include' });
  const data = await parseJson(res);
  if (!res.ok) {
    return { stripeEnabled: false, simulationAllowed: false, publishableKey: null };
  }
  return {
    stripeEnabled: Boolean(data?.stripeEnabled),
    simulationAllowed: Boolean(data?.simulationAllowed),
    publishableKey: typeof data?.publishableKey === 'string' ? data.publishableKey : null,
  };
}

/**
 * @param {'starter'|'pro'} plan
 * @param {number} [organizationId] — admin uniquement
 * @returns {Promise<{ ok: boolean, checkoutUrl?: string, error?: string, code?: string }>}
 */
export async function startStripeCheckout(plan, organizationId) {
  const body = { plan };
  if (organizationId != null) body.organizationId = organizationId;
  const res = await fetch('/api/billing/checkout', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await parseJson(res);
  if (!res.ok) {
    return { ok: false, error: data?.error ?? 'Paiement impossible', code: data?.code };
  }
  if (typeof data?.checkoutUrl === 'string' && data.checkoutUrl !== '') {
    const safe = safeStripeRedirectUrl(data.checkoutUrl);
    if (!safe) {
      return { ok: false, error: 'URL de paiement non autorisée' };
    }
    return { ok: true, checkoutUrl: safe };
  }
  return { ok: false, error: 'Réponse Stripe invalide' };
}

/**
 * @param {number} [organizationId]
 * @returns {Promise<{ ok: boolean, portalUrl?: string, error?: string }>}
 */
export async function openStripeCustomerPortal(organizationId) {
  const body = {};
  if (organizationId != null) body.organizationId = organizationId;
  const res = await fetch('/api/billing/portal', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await parseJson(res);
  if (!res.ok) {
    return { ok: false, error: data?.error ?? 'Portail indisponible' };
  }
  if (typeof data?.portalUrl === 'string' && data.portalUrl !== '') {
    const safe = safeStripeRedirectUrl(data.portalUrl);
    if (!safe) {
      return { ok: false, error: 'URL de portail non autorisée' };
    }
    return { ok: true, portalUrl: safe };
  }
  return { ok: false, error: 'Réponse Stripe invalide' };
}
