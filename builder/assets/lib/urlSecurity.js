/**
 * Garde-fous pour liens et redirections issus de données métier.
 */

const SAFE_PROTOCOLS = new Set(['http:', 'https:', 'mailto:']);

const STRIPE_HOSTS = new Set([
  'checkout.stripe.com',
  'billing.stripe.com',
  'pay.stripe.com',
  'js.stripe.com',
]);

/**
 * Chemins relatifs same-origin uniquement (rejette //evil.com).
 * @param {string} t
 */
function isSafeRelativePath(t) {
  if (t.startsWith('//')) return false;
  if (t.startsWith('/') || t.startsWith('./') || t.startsWith('../') || t.startsWith('#')) {
    return true;
  }
  return false;
}

/**
 * @param {string|null|undefined} href
 * @returns {boolean}
 */
export function isSafePublicHref(href) {
  if (href == null || href === '') return false;
  const t = String(href).trim();
  if (isSafeRelativePath(t)) return true;
  try {
    const u = new URL(t);
    return SAFE_PROTOCOLS.has(u.protocol);
  } catch {
    return false;
  }
}

/**
 * @param {string|null|undefined} href
 * @returns {string|null}
 */
export function safeHrefOrNull(href) {
  return isSafePublicHref(href) ? String(href).trim() : null;
}

/**
 * Autorise uniquement les redirections Stripe Checkout / Customer Portal (ou same-origin).
 * @param {string|null|undefined} url
 * @returns {string|null}
 */
export function safeStripeRedirectUrl(url) {
  if (url == null || url === '') return null;
  const t = String(url).trim();
  if (isSafeRelativePath(t)) {
    return t.startsWith('/') ? t : null;
  }
  try {
    const u = new URL(t);
    if (u.protocol !== 'https:') return null;
    const host = u.hostname.toLowerCase();
    if (STRIPE_HOSTS.has(host) || host.endsWith('.stripe.com')) {
      return u.toString();
    }
    if (typeof window !== 'undefined' && host === window.location.hostname) {
      return u.toString();
    }
  } catch {
    return null;
  }
  return null;
}
