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

function isApiRequest(input) {
  let urlStr = '';
  if (typeof Request !== 'undefined' && input instanceof Request) {
    urlStr = input.url;
  } else {
    urlStr = typeof input === 'string' ? input : String(input);
  }
  try {
    const u = new URL(urlStr, window.location.origin);
    const path = u.pathname.replace(/\/+$/, '') || '/';
    return path.startsWith('/api/');
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
    const isLoginPost = isPostToLoginApi(input, init);
    if (isLoginPost) return res;

    // 401/403 : session absente ou invalide alors que l’app se croit connectée.
    if (res.status === 401 || res.status === 403) {
      if (!appKnowsUserLoggedIn) return res;
      appKnowsUserLoggedIn = false;
      window.dispatchEvent(new CustomEvent('webhooky:session-expired'));
      return res;
    }

    // Certaines configurations renvoient une redirection HTML (ou page d’erreur) sur un appel API au lieu d’un JSON/401.
    if (appKnowsUserLoggedIn && isApiRequest(input)) {
      const ct = String(res.headers.get('Content-Type') || '').toLowerCase();
      if (ct.includes('text/html')) {
        appKnowsUserLoggedIn = false;
        window.dispatchEvent(new CustomEvent('webhooky:session-expired'));
      }
    }

    return res;
  };
}
