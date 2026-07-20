/**
 * Identité légale — valeurs lues depuis les variables d’environnement PUBLIC_LEGAL_*.
 * Ne jamais inventer SIREN, adresse ou capital. Si vides, les pages restent honnêtes.
 *
 * Validation juridique externe requise avant commercialisation.
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

export const legalConfig: LegalConfig = {
  publisherName: env('PUBLIC_LEGAL_PUBLISHER_NAME'),
  legalForm: env('PUBLIC_LEGAL_FORM'),
  capital: env('PUBLIC_LEGAL_CAPITAL'),
  address: env('PUBLIC_LEGAL_ADDRESS'),
  siren: env('PUBLIC_LEGAL_SIREN'),
  siret: env('PUBLIC_LEGAL_SIRET'),
  rcs: env('PUBLIC_LEGAL_RCS'),
  vatNumber: env('PUBLIC_LEGAL_VAT'),
  publicationDirector: env('PUBLIC_LEGAL_PUBLICATION_DIRECTOR'),
  hostName: env('PUBLIC_LEGAL_HOST_NAME'),
  hostAddress: env('PUBLIC_LEGAL_HOST_ADDRESS'),
  hostContact: env('PUBLIC_LEGAL_HOST_CONTACT'),
  contactEmail: env('PUBLIC_LEGAL_CONTACT_EMAIL') || 'contact@webhooky.fr',
  securityEmail: env('PUBLIC_LEGAL_SECURITY_EMAIL'),
  lastUpdated: env('PUBLIC_LEGAL_LAST_UPDATED') || '2026-07-20',
  governingLaw: env('PUBLIC_LEGAL_GOVERNING_LAW') || 'Droit français',
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
] as const;
