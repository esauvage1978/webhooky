/**
 * Garde session : 401 sur l’API alors que l’app affiche un utilisateur connecté → événement custom.
 * Évite le 401 initial de GET /api/me (utilisateur non connecté) et POST /api/login (mauvais mot de passe).
 */

let appKnowsUserLoggedIn = false;

export function setAppSessionKnownLoggedIn(value) {
  appKnowsUserLoggedIn = !!value;
}

function isPostToLoginApi(input, init) {
  let method = 'GET';
  let urlStr = '';
  if (typeof Request !== 'undefined' && input instanceof Request) {
    method = String(input.method || 'GET').toUpperCase();
    urlStr = input.url;
  } else {
    method = String(init?.method ?? 'GET').toUpperCase();
    urlStr = typeof input === 'string' ? input : String(input);
  }
  if (method !== 'POST') return false;
  try {
    const u = new URL(urlStr, window.location.origin);
    const path = u.pathname.replace(/\/+$/, '') || '/';
    return path.endsWith('/api/login');
  } catch {
    return false;
  }
}

let patched = false;

export function installGlobalFetch401Handler() {
  if (patched || typeof window === 'undefined') return;
  patched = true;
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const res = await nativeFetch(input, init);
    if (res.status !== 401) return res;
    /* Toujours avant « logged in » : sinon un 2ᵉ onglet avec mauvais mot de passe déclencherait une fausse expiration. */
    if (isPostToLoginApi(input, init)) return res;
    if (!appKnowsUserLoggedIn) return res;
    appKnowsUserLoggedIn = false;
    window.dispatchEvent(new CustomEvent('webhooky:session-expired'));
    return res;
  };
}
