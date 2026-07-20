/**
 * Constantes globales du site vitrine.
 * Les URLs d’auth doivent rester alignées avec builder/assets/app/routing.js.
 */
export const SITE_URL = 'https://webhooky.fr';
export const SITE_NAME = 'Webhooky';
export const SITE_TAGLINE = 'Centralisez vos webhooks et automatisez les actions qui suivent.';
export const CONTACT_EMAIL = 'contact@webhooky.fr';
/** Adresse utilisée par l’application (notifications / contact plateforme). */
export const BUILDER_CONTACT_EMAIL = 'contact@webhooky.builders';

export const BUILDER_URL = 'https://webhooky.builders';
export const BUILDER_REGISTER_URL = `${BUILDER_URL}/inscription`;
export const BUILDER_LOGIN_URL = `${BUILDER_URL}/`;
export const BUILDER_FORGOT_PASSWORD_URL = `${BUILDER_URL}/mot-de-passe-oublie`;
export const BUILDER_BILLING_URL = `${BUILDER_URL}/facturation`;

/**
 * Webhook formulaire contact — ne pas committer de jeton réel.
 * Prod : POST same-origin `/contact-webhook.php` (URL serveur CONTACT_WEBHOOK_FORWARD_URL).
 * Dev : définir PUBLIC_CONTACT_WEBHOOK_URL dans `.env`.
 */
export const CONTACT_FORM_WEBHOOK_DEFAULT = '';

export const COOKIE_CONSENT_STORAGE_KEY = 'webhooky_cookie_consent';
export const COOKIE_CONSENT_VERSION = 2;

export const GOOGLE_ANALYTICS_ID = 'G-4247L6EFFF';

export const VALUE_PROPOSITION = {
  headline: 'Centralisez vos webhooks et automatisez les actions qui suivent',
  subheadline:
    'Recevez les données de vos formulaires et applications, déclenchez plusieurs actions, connectez vos prestataires et suivez chaque exécution depuis une interface unique.',
  badge: 'Webhooks et automatisations',
} as const;

export const SOCIAL_OG_IMAGE = '/og-default.svg';
