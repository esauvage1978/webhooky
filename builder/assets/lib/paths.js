/** Préfixe Symfony (`app.request.basePath`), ex. `/webhooky/public` sous WAMP. */
export function getWebhookyBasePath() {
  if (typeof window === 'undefined') return '';
  const b = window.__WEBHOOKY_BASE_PATH__;
  if (typeof b !== 'string' || b === '') return '';
  return b.replace(/\/$/, '');
}

/** @param {string} path */
export function absoluteAppPath(path) {
  const base = getWebhookyBasePath();
  const rel = path.startsWith('/') ? path : `/${path}`;
  if (!base) return rel;
  if (rel === '/') return base;
  return `${base}${rel}`;
}
