/**
 * Identité légale — variables PUBLIC_LEGAL_* (override) avec valeurs par défaut
 * alignées sur https://emmanuelsauvage.fr/mentions-legales/ (éditeur) et o2switch (hébergeur).
 *
 * Ne pas inventer rue, capital, RCS ou TVA s’ils ne sont pas connus.
 * Validation juridique externe recommandée avant commercialisation.
 */
export interface LegalConfig {
  publisherName: string;
  legalForm: string;
  capital: string;
  address: string;
  siren: string;
  siret: string;
  rcs: string;
  vatNumber: string;
  publicationDirector: string;
  hostName: string;
  hostAddress: string;
  hostContact: string;
  contactEmail: string;
  securityEmail: string;
  lastUpdated: string;
  governingLaw: string;
  jurisdiction: string;
}

function env(key: string): string {
  const v = import.meta.env[key];
  return typeof v === 'string' ? v.trim() : '';
}

function envOr(key: string, fallback: string): string {
  return env(key) || fallback;
}

export const legalConfig: LegalConfig = {
  publisherName: envOr('PUBLIC_LEGAL_PUBLISHER_NAME', 'Emmanuel SAUVAGE'),
  legalForm: envOr('PUBLIC_LEGAL_FORM', 'Consultant indépendant'),
  capital: env('PUBLIC_LEGAL_CAPITAL'),
  address: envOr('PUBLIC_LEGAL_ADDRESS', 'Erquinghem-Lys, France'),
  siren: envOr('PUBLIC_LEGAL_SIREN', '887 814 168'),
  siret: envOr('PUBLIC_LEGAL_SIRET', '887 814 168 00019'),
  rcs: env('PUBLIC_LEGAL_RCS'),
  vatNumber: env('PUBLIC_LEGAL_VAT'),
  publicationDirector: envOr('PUBLIC_LEGAL_PUBLICATION_DIRECTOR', 'Emmanuel SAUVAGE'),
  hostName: envOr('PUBLIC_LEGAL_HOST_NAME', 'o2switch'),
  hostAddress: envOr(
    'PUBLIC_LEGAL_HOST_ADDRESS',
    '222-224 Boulevard Gustave Flaubert, 63000 Clermont-Ferrand, France',
  ),
  hostContact: envOr('PUBLIC_LEGAL_HOST_CONTACT', '04 44 44 60 40 — https://www.o2switch.fr'),
  contactEmail: envOr('PUBLIC_LEGAL_CONTACT_EMAIL', 'contact@webhooky.fr'),
  securityEmail: envOr('PUBLIC_LEGAL_SECURITY_EMAIL', 'contact@webhooky.fr'),
  lastUpdated: envOr('PUBLIC_LEGAL_LAST_UPDATED', '2026-07-21'),
  governingLaw: envOr('PUBLIC_LEGAL_GOVERNING_LAW', 'Droit français'),
  jurisdiction: env('PUBLIC_LEGAL_JURISDICTION'),
};

/** Champs obligatoires pour une publication « mentions légales » complète (France). */
export const REQUIRED_LEGAL_FIELDS: (keyof LegalConfig)[] = [
  'publisherName',
  'legalForm',
  'address',
  'publicationDirector',
  'hostName',
  'hostAddress',
];

export function missingLegalFields(config: LegalConfig = legalConfig): (keyof LegalConfig)[] {
  return REQUIRED_LEGAL_FIELDS.filter((key) => !config[key]);
}

export function isLegalIdentityComplete(config: LegalConfig = legalConfig): boolean {
  return missingLegalFields(config).length === 0;
}

export const LEGAL_DISCLAIMER =
  'Les textes juridiques de ce site sont fournis à titre informatif et technique. Une validation par un professionnel du droit reste nécessaire avant toute utilisation commerciale définitive.';

export const subprocessors = [
  {
    name: 'Stripe',
    purpose: 'Paiement des abonnements (lorsque activé)',
    region: 'UE / selon configuration Stripe',
  },
  {
    name: 'Mailjet',
    purpose: 'Envoi d’e-mails transactionnels de la plateforme et connecteur client',
    region: 'Selon contrat Mailjet',
  },
  {
    name: 'Google Analytics',
    purpose: 'Mesure d’audience du site vitrine (uniquement après consentement)',
    region: 'Selon configuration Google',
  },
  {
    name: 'o2switch',
    purpose: 'Hébergement du site vitrine et de l’infrastructure associée',
    region: 'France (UE)',
  },
] as const;
