export interface UseCase {
  id: string;
  title: string;
  audience: string;
  problem: string;
  flow: string[];
  result: string;
  ctaHref: string;
  ctaLabel: string;
}

export const useCases: UseCase[] = [
  {
    id: 'contact-form-mailjet',
    title: 'Formulaire de contact d’un site vitrine',
    audience: 'Agences & freelances',
    problem: 'Chaque site embarque sa propre logique d’envoi d’e-mails et disperse les clés API.',
    flow: [
      'Le formulaire poste vers une URL Webhooky',
      'Webhooky valide et journalise le payload',
      'Une action Mailjet envoie la notification',
      'L’historique centralise succès et erreurs',
    ],
    result: 'Une seule URL à maintenir, des secrets hors du site, et une trace claire de chaque envoi.',
    ctaHref: '/integrations/mailjet/',
    ctaLabel: 'Voir l’intégration Mailjet',
  },
  {
    id: 'quote-request',
    title: 'Formulaire de demande de devis',
    audience: 'PME & équipes commerciales',
    problem: 'Les demandes de devis se perdent entre boîte mail, tableur et outils internes.',
    flow: [
      'Réception de la demande via webhook',
      'Notification interne (Slack / Teams / e-mail)',
      'Confirmation automatique au prospect',
      'Suivi dans les journaux Webhooky',
    ],
    result: 'Chaque demande déclenche le bon circuit sans coder une intégration par outil.',
    ctaHref: '/tarifs/',
    ctaLabel: 'Voir les forfaits',
  },
  {
    id: 'dual-notify',
    title: 'Confirmation client et notification interne',
    audience: 'Tous profils',
    problem: 'Il faut à la fois rassurer l’utilisateur et alerter l’équipe, sans doubler la logique métier.',
    flow: [
      'Un seul événement entrant',
      'Action 1 : e-mail de confirmation',
      'Action 2 : notification équipe',
      'Consultation du statut de chaque action',
    ],
    result: 'Deux (ou plus) actions à partir d’une URL, avec diagnostic action par action.',
    ctaHref: '/fonctionnalites/',
    ctaLabel: 'Explorer les fonctionnalités',
  },
  {
    id: 'agency-multi-sites',
    title: 'Agence gérant plusieurs sites clients',
    audience: 'Agences',
    problem: 'Les clés API et scripts d’envoi sont dupliqués dans chaque projet client.',
    flow: [
      'Organisation agence dans Webhooky',
      'Un webhook (ou projet) par site client',
      'Connecteurs partagés ou dédiés',
      'Supervision consolidée des exécutions',
    ],
    result: 'Maintenance centralisée, onboarding client plus rapide, moins de dette technique.',
    ctaHref: '/solutions/agences/',
    ctaLabel: 'Solution agences',
  },
  {
    id: 'saas-fanout',
    title: 'SaaS envoyant des événements vers plusieurs services',
    audience: 'Éditeurs SaaS',
    problem: 'Le produit métier ne doit pas devenir un routeur d’intégrations fragile.',
    flow: [
      'L’application émet un événement vers Webhooky',
      'Webhooky orchestre e-mail, SMS, HTTP…',
      'Quotas et journaux côté plateforme',
      'Le cœur métier reste découplé',
    ],
    result: 'Moins de code spécifique fournisseurs, meilleure traçabilité des flux sortants.',
    ctaHref: '/solutions/saas/',
    ctaLabel: 'Solution SaaS',
  },
  {
    id: 'swap-email-provider',
    title: 'Remplacer un fournisseur d’e-mails sans toucher aux formulaires',
    audience: 'Développeurs',
    problem: 'Changer de prestataire implique de redéployer chaque site ou service.',
    flow: [
      'Les formulaires gardent la même URL Webhooky',
      'Mise à jour du connecteur dans l’organisation',
      'Nouveau mapping de template si besoin',
      'Tests puis bascule',
    ],
    result: 'Réduction de la dépendance à un fournisseur unique côté sites clients.',
    ctaHref: '/solutions/developpeurs/',
    ctaLabel: 'Solution développeurs',
  },
  {
    id: 'central-logs',
    title: 'Centraliser les journaux de plusieurs applications',
    audience: 'Équipes techniques',
    problem: 'Les échecs d’intégrations sont dispersés dans des logs applicatifs hétérogènes.',
    flow: [
      'Chaque app pointe vers Webhooky',
      'Exécutions regroupées par organisation / projet',
      'Statuts et erreurs consultables au même endroit',
      'Actions correctives ciblées',
    ],
    result: 'Un point de vérité pour diagnostiquer les automatisations cross-applications.',
    ctaHref: '/documentation/',
    ctaLabel: 'Démarrer avec la doc',
  },
];
