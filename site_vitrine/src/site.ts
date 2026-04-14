export const BUILDER_URL = 'https://webhooky.builders';
export const SITE_URL = 'https://webhooky.fr';
/** Nom court pour balises Open Graph, JSON-LD et fil d’Ariane. */
export const SITE_NAME = 'Webhooky';
export const CONTACT_EMAIL = 'contact@webhooky.fr';

/** Webhook formulaire (ingress) — surchargé par PUBLIC_CONTACT_WEBHOOK_URL si défini. */
export const CONTACT_FORM_WEBHOOK_DEFAULT =
  'https://webhooky.builders/webhook/form/0b40160efaa364470731-3f7d-48b8-aabf-817f08ce8b42';

/** Préférences cookies (localStorage) — incrémenter pour forcer une nouvelle décision. */
export const COOKIE_CONSENT_STORAGE_KEY = 'webhooky_cookie_consent';
export const COOKIE_CONSENT_VERSION = 1;

/** Google Analytics 4 (gtag) — chargé uniquement si consentement « mesure d’audience ». */
export const GOOGLE_ANALYTICS_ID = 'G-4247L6EFFF';
