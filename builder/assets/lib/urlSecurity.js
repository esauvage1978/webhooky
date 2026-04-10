/**
 * Garde-fous pour liens et redirections issus de données métier (réduction des risques javascript: / data:).
 *
 * React échappe le texte des enfants ; le risque principal est `href={valeurUtilisateur}`.
 */

const SAFE_PROTOCOLS = new Set(['http:', 'https:', 'mailto:']);

/**
 * @param {string|null|undefined} href
 * @returns {boolean}
 */
export function isSafePublicHref(href) {
  if (href == null || href === '') return false;
  const t = String(href).trim();
  if (t.startsWith('/') || t.startsWith('./') || t.startsWith('../') || t.startsWith('#')) {
    return true;
  }
  try {
    const u = new URL(t, typeof window !== 'undefined' ? window.location.origin : 'https://localhost');
    return SAFE_PROTOCOLS.has(u.protocol);
  } catch {
    return false;
  }
}

/**
 * @param {string|null|undefined} href
 * @returns {string|null} href si sûr, sinon null
 */
export function safeHrefOrNull(href) {
  return isSafePublicHref(href) ? String(href).trim() : null;
}
