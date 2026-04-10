/**
 * HTTP client conventions — API same-origin ( Symfony ).
 *
 * Sécurité :
 * - Toujours `credentials: 'include'` pour les routes sous session (cookie HttpOnly côté serveur).
 * - Ne jamais placer de secrets dans l’URL (query) ; préférer le corps JSON ou les en-têtes serveur.
 * - `parseJson` lit le corps en texte puis JSON.parse : évite les erreurs silencieuses si le serveur
 *   renvoie HTML (page d’erreur) au lieu de JSON.
 */

export const API_FETCH_DEFAULTS = {
  credentials: 'include',
};

export const API_JSON_HEADERS = {
  Accept: 'application/json',
};

/**
 * @param {Response} res
 * @returns {Promise<unknown|null>}
 */
export async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * Options fetch fusionnées pour un appel API JSON avec session cookie.
 * @param {RequestInit} [init]
 * @returns {RequestInit}
 */
export function apiJsonInit(init = {}) {
  return {
    ...API_FETCH_DEFAULTS,
    ...init,
    headers: {
      ...API_JSON_HEADERS,
      ...(init.headers && typeof init.headers === 'object' && !Array.isArray(init.headers)
        ? init.headers
        : {}),
    },
  };
}

/**
 * GET JSON (session incluse).
 * @param {string|URL} url
 * @param {RequestInit} [init]
 */
export async function apiGetJson(url, init = {}) {
  return fetch(url, apiJsonInit({ method: 'GET', ...init }));
}

/**
 * POST JSON (corps déjà sérialisé ou objet passé à body via init).
 * @param {string|URL} url
 * @param {RequestInit} [init]
 */
export async function apiPostJson(url, init = {}) {
  const headers = { 'Content-Type': 'application/json', ...((init.headers && typeof init.headers === 'object') ? init.headers : {}) };
  return fetch(url, apiJsonInit({ method: 'POST', ...init, headers }));
}
