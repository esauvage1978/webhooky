import type { NavItem } from './types';
import {
  BUILDER_LOGIN_URL,
  BUILDER_REGISTER_URL,
  CONTACT_EMAIL,
} from './site';

/** Navigation desktop : Produit (dropdown) + entrées principales du brief. */
export const headerNav: NavItem[] = [
  {
    href: '/fonctionnalites/',
    label: 'Produit',
    children: [
      { href: '/fonctionnalites/', label: 'Fonctionnalités' },
      { href: '/securite/', label: 'Sécurité' },
      { href: '/roadmap/', label: 'Feuille de route' },
      { href: '/a-propos/', label: 'À propos' },
    ],
  },
  { href: '/cas-usage/', label: 'Cas d’usage' },
  { href: '/integrations/', label: 'Intégrations' },
  { href: '/tarifs/', label: 'Tarifs' },
  { href: '/documentation/', label: 'Documentation' },
  { href: '/contact/', label: 'Contact' },
];

/** Navigation mobile : liste plate (Produit déplié). */
export const mobileNav: NavItem[] = [
  { href: '/fonctionnalites/', label: 'Fonctionnalités' },
  { href: '/securite/', label: 'Sécurité' },
  { href: '/cas-usage/', label: 'Cas d’usage' },
  { href: '/integrations/', label: 'Intégrations' },
  { href: '/tarifs/', label: 'Tarifs' },
  { href: '/documentation/', label: 'Documentation' },
  { href: '/roadmap/', label: 'Feuille de route' },
  { href: '/a-propos/', label: 'À propos' },
  { href: '/contact/', label: 'Contact' },
];

export const headerActions = {
  login: { href: BUILDER_LOGIN_URL, label: 'Se connecter' },
  register: { href: BUILDER_REGISTER_URL, label: 'Créer un compte' },
} as const;

export const footerNav = {
  product: [
    { href: '/fonctionnalites/', label: 'Fonctionnalités' },
    { href: '/cas-usage/', label: 'Cas d’usage' },
    { href: '/integrations/', label: 'Intégrations' },
    { href: '/tarifs/', label: 'Tarifs' },
    { href: '/securite/', label: 'Sécurité' },
    { href: '/roadmap/', label: 'Feuille de route' },
  ],
  resources: [
    { href: '/documentation/', label: 'Documentation' },
    { href: '/solutions/developpeurs/', label: 'Développeurs' },
    { href: '/solutions/agences/', label: 'Agences' },
    { href: '/solutions/saas/', label: 'SaaS' },
    { href: '/blog/', label: 'Blog' },
    { href: '/a-propos/', label: 'À propos' },
    { href: '/contact/', label: 'Contact' },
  ],
  legal: [
    { href: '/mentions-legales/', label: 'Mentions légales' },
    { href: '/politique-confidentialite/', label: 'Confidentialité' },
    { href: '/cgu/', label: 'CGU' },
    { href: '/cgv/', label: 'CGV' },
  ],
} as const;

export const contactMailto = `mailto:${CONTACT_EMAIL}`;
