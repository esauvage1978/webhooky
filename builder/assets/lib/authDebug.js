/**
 * Traces côté navigateur pour le flux session / auth.
 * En production : exécuter une fois `localStorage.setItem('webhooky_debug_auth', '1')` puis recharger.
 * Le build de dev Vite logue sans cette clé.
 */
export function authDebug(label, detail) {
  const dev = import.meta.env.DEV;
  let enabled = dev;
  if (!enabled && typeof localStorage !== 'undefined') {
    try {
      enabled = localStorage.getItem('webhooky_debug_auth') === '1';
    } catch {
      /* ignore */
    }
  }
  if (!enabled) return;
  if (detail !== undefined) {
    console.info('[Webhooky auth]', label, detail);
  } else {
    console.info('[Webhooky auth]', label);
  }
}
