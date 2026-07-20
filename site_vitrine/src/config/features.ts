import type { ProductFeature } from './types';

/**
 * Statuts alignés sur le code builder (ServiceIntegrationType, FormWebhook, builtins UI).
 * Ne pas marquer « available » sans confirmation applicative.
 */
export const features: ProductFeature[] = [
  // Collecter
  {
    id: 'inbound-webhooks',
    title: 'Webhooks entrants',
    description: 'Recevez des événements via une URL dédiée (POST, GET, OPTIONS) et journalisez chaque payload.',
    status: 'available',
    category: 'collect',
    documentationUrl: '/documentation/',
  },
  {
    id: 'form-payloads',
    title: 'Payloads formulaires et JSON',
    description: 'Acceptez des données de formulaires web ou d’applications sous forme JSON structurée.',
    status: 'available',
    category: 'collect',
  },
  {
    id: 'headers-params',
    title: 'Paramètres et en-têtes utiles',
    description: 'Exploitez le contexte de la requête pour alimenter vos actions (corps, paramètres).',
    status: 'available',
    category: 'collect',
  },
  {
    id: 'draft-production',
    title: 'Modes brouillon et production',
    description: 'Testez sans consommer de quota en brouillon, puis activez le scénario en production.',
    status: 'available',
    category: 'collect',
  },

  // Orchestrer
  {
    id: 'multi-actions',
    title: 'Plusieurs actions par événement',
    description: 'Chaînez plusieurs actions à partir d’une seule URL de webhook.',
    status: 'available',
    category: 'orchestrate',
  },
  {
    id: 'dynamic-variables',
    title: 'Variables dynamiques',
    description: 'Mappez les champs du payload vers vos modèles, destinataires et appels HTTP.',
    status: 'available',
    category: 'orchestrate',
  },
  {
    id: 'action-order',
    title: 'Ordre des actions',
    description: 'Définissez la séquence d’exécution des actions dans le scénario.',
    status: 'available',
    category: 'orchestrate',
  },
  {
    id: 'conditions',
    title: 'Conditions et branchements',
    description: 'Exécutez des actions selon des conditions sur les données reçues.',
    status: 'beta',
    category: 'orchestrate',
  },
  {
    id: 'transforms',
    title: 'Transformations de données',
    description: 'Parsez et transformez des payloads JSON avant les actions suivantes.',
    status: 'beta',
    category: 'orchestrate',
  },
  {
    id: 'ai-action',
    title: 'Action IA',
    description: 'Enrichissez un scénario via une action IA (provider Ollama actuellement).',
    status: 'beta',
    category: 'orchestrate',
  },

  // Connecter
  {
    id: 'mailjet',
    title: 'Intégration Mailjet',
    description: 'Envoyez des e-mails transactionnels via modèles Mailjet et variables dynamiques.',
    status: 'available',
    category: 'connect',
    documentationUrl: '/integrations/mailjet/',
  },
  {
    id: 'messaging',
    title: 'Messagerie (Slack, Teams, Discord…)',
    description: 'Notifier des équipes via Slack, Microsoft Teams, Discord, Google Chat, Mattermost ou Telegram.',
    status: 'available',
    category: 'connect',
    documentationUrl: '/integrations/',
  },
  {
    id: 'sms',
    title: 'SMS (Twilio, Vonage, MessageBird)',
    description: 'Déclenchez des SMS via les connecteurs SMS disponibles dans l’application.',
    status: 'available',
    category: 'connect',
  },
  {
    id: 'http-out',
    title: 'Appels HTTP sortants',
    description: 'Transférez un événement vers une API ou une URL personnalisée.',
    status: 'available',
    category: 'connect',
  },
  {
    id: 'secrets-central',
    title: 'Identifiants centralisés',
    description: 'Stockez les clés et connexions au niveau de l’organisation, plutôt que dans chaque site.',
    status: 'available',
    category: 'connect',
  },
  {
    id: 'swappable-providers',
    title: 'Fournisseurs interchangeables',
    description: 'Changez de connecteur sans modifier l’URL publique de vos formulaires.',
    status: 'available',
    category: 'connect',
  },

  // Superviser
  {
    id: 'execution-logs',
    title: 'Journaux d’exécution',
    description: 'Consultez le statut de chaque exécution et les détails utiles au diagnostic.',
    status: 'available',
    category: 'supervise',
  },
  {
    id: 'error-tracking',
    title: 'Suivi des erreurs',
    description: 'Identifiez les échecs d’actions et les motifs de rejet (quota, configuration…).',
    status: 'available',
    category: 'supervise',
  },
  {
    id: 'test-send',
    title: 'Envoi de tests',
    description: 'Déclenchez un test depuis l’interface pour valider un scénario avant la mise en production.',
    status: 'available',
    category: 'supervise',
  },
  {
    id: 'replay',
    title: 'Rejeu d’événements',
    description: 'Rejouer un événement échoué depuis l’historique pour corriger et relancer.',
    status: 'planned',
    category: 'supervise',
  },
  {
    id: 'alerts',
    title: 'Alertes proactives',
    description: 'Alertes dédiées en cas d’échecs répétés ou de seuils de quota.',
    status: 'planned',
    category: 'supervise',
  },

  // Administrer
  {
    id: 'organizations',
    title: 'Organisations et membres',
    description: 'Isolez les projets par organisation et invitez des collaborateurs.',
    status: 'available',
    category: 'admin',
  },
  {
    id: 'quotas',
    title: 'Gestion des quotas',
    description: 'Suivez la consommation d’événements et bloquez proprement les dépassements.',
    status: 'available',
    category: 'admin',
  },
  {
    id: 'subscriptions',
    title: 'Abonnements',
    description: 'Forfaits Free, Starter et Pro rattachés à l’organisation, avec paiement Stripe.',
    status: 'available',
    category: 'admin',
    documentationUrl: '/tarifs/',
  },
  {
    id: 'projects',
    title: 'Projets',
    description: 'Organisez vos webhooks et connecteurs par projet au sein d’une organisation.',
    status: 'available',
    category: 'admin',
  },
  {
    id: 'environments',
    title: 'Environnements distincts',
    description: 'Séparation formelle des environnements (dev / staging / prod) au-delà du mode brouillon.',
    status: 'planned',
    category: 'admin',
  },
  {
    id: 'public-api-sdk',
    title: 'API publique et SDK',
    description: 'API et SDK documentés pour automatiser la configuration hors interface.',
    status: 'planned',
    category: 'admin',
  },
];

export const featureCategories: { id: ProductFeature['category']; title: string; intro: string }[] = [
  {
    id: 'collect',
    title: 'Collecter',
    intro: 'Recevez les événements depuis vos formulaires et applications.',
  },
  {
    id: 'orchestrate',
    title: 'Orchestrer',
    intro: 'Enchaînez actions, variables et logique métier.',
  },
  {
    id: 'connect',
    title: 'Connecter',
    intro: 'Branchez vos prestataires sans disperser les secrets.',
  },
  {
    id: 'supervise',
    title: 'Superviser',
    intro: 'Suivez les exécutions et diagnostiquez les erreurs.',
  },
  {
    id: 'admin',
    title: 'Administrer',
    intro: 'Organisations, quotas, projets et abonnements.',
  },
];
