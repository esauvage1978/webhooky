import type { FaqItem } from './types';

export const homeFaq: FaqItem[] = [
  {
    id: 'what-is-webhook',
    question: 'Qu’est-ce qu’un webhook ?',
    answer:
      'Un webhook est une URL que vos applications ou formulaires appellent pour transmettre un événement (souvent en JSON). Webhooky reçoit cet événement, puis exécute les actions que vous avez configurées.',
  },
  {
    id: 'what-is-webhooky',
    question: 'À quoi sert Webhooky ?',
    answer:
      'Webhooky centralise la réception des webhooks, orchestre plusieurs actions (e-mail, SMS, messagerie, HTTP…), conserve un historique d’exécution et isole la configuration par organisation.',
  },
  {
    id: 'need-developer',
    question: 'Faut-il être développeur ?',
    answer:
      'Une base technique aide pour brancher un formulaire ou une API, mais l’interface permet de configurer connecteurs et actions sans tout coder. Les profils non techniques peuvent s’appuyer sur un prestataire pour le branchement initial.',
  },
  {
    id: 'wordpress',
    question: 'Puis-je connecter un formulaire WordPress ?',
    answer:
      'Oui, dès que le formulaire peut envoyer une requête HTTP (POST) vers votre URL Webhooky — via un plugin webhook, un outil de formulaires compatible, ou un petit script. Webhooky ne fournit pas de plugin WordPress dédié à ce jour.',
  },
  {
    id: 'mailjet-required',
    question: 'Mailjet est-il obligatoire ?',
    answer:
      'Non. Mailjet est l’un des connecteurs disponibles. Vous pouvez aussi utiliser des notifications (Slack, Teams…), des SMS, des appels HTTP, etc., selon vos besoins.',
  },
  {
    id: 'api-keys-storage',
    question: 'Où sont stockées les clés API ?',
    answer:
      'Les identifiants de connecteurs sont stockés côté application, au niveau de l’organisation, et exposés masqués dans l’interface. Certaines données OAuth sensibles sont chiffrées au repos ; les clés Mailjet sont stockées de façon sécurisée côté serveur et ne doivent jamais être placées dans le code de vos sites.',
  },
  {
    id: 'action-fails',
    question: 'Que se passe-t-il si une action échoue ?',
    answer:
      'L’échec est journalisé avec les informations utiles au diagnostic. Vous pouvez corriger la configuration puis renvoyer un test. Le rejeu automatique depuis l’historique est prévu mais pas encore disponible.',
  },
  {
    id: 'replay',
    question: 'Puis-je rejouer un événement ?',
    answer:
      'Vous pouvez renvoyer un test depuis l’interface. Le rejeu d’un événement passé depuis les journaux est une fonctionnalité prévue, pas encore proposée comme disponible.',
  },
  {
    id: 'quota-reached',
    question: 'Que se passe-t-il quand le quota est atteint ?',
    answer:
      'Les nouvelles exécutions sont refusées (HTTP 402) jusqu’à un changement de forfait ou, sur Starter/Pro, l’ajout d’événements via packs. Il n’y a pas de facturation automatique au dépassement.',
  },
  {
    id: 'change-plan',
    question: 'Puis-je changer de formule ?',
    answer:
      'Oui. Les forfaits sont gérés au niveau de l’organisation. Vous pouvez démarrer en Free puis passer à Starter ou Pro depuis l’application (parcours de facturation Stripe).',
  },
  {
    id: 'personal-data',
    question: 'Comment sont traitées les données personnelles ?',
    answer:
      'Les données transmises dans vos webhooks sont traitées pour exécuter vos scénarios et assurer le fonctionnement du service. Consultez la politique de confidentialité pour les finalités, durées et droits. Vous restez responsable des données que vous envoyez et des destinataires contactés.',
  },
  {
    id: 'delete-data',
    question: 'Puis-je supprimer mes données ?',
    answer:
      'Vous pouvez demander la suppression de votre compte et des données associées via le contact indiqué dans la politique de confidentialité. Les modalités exactes dépendent de votre organisation et des obligations légales de conservation.',
  },
  {
    id: 'agencies',
    question: 'Le service convient-il aux agences ?',
    answer:
      'Oui. Les organisations permettent de séparer clients ou projets, de centraliser les connecteurs et de superviser les exécutions sans disperser les secrets dans chaque site.',
  },
  {
    id: 'public-api',
    question: 'Une API est-elle disponible ?',
    answer:
      'L’application expose des API internes pour son interface. Une API publique documentée et un SDK destinés aux intégrateurs tiers sont prévus, mais pas encore proposés comme produit disponible.',
  },
];

export const pricingFaq: FaqItem[] = [
  {
    id: 'what-is-event',
    question: 'Qu’est-ce qu’un événement facturé ?',
    answer:
      'Un événement correspond à une action exécutée dans un scénario. Trois actions sur un même webhook comptent pour trois événements. Les modes brouillon ou webhooks désactivés ne consomment pas le quota.',
  },
  {
    id: 'vat',
    question: 'Les prix sont-ils TTC ?',
    answer:
      'Non. Les montants affichés sont hors taxes (HT). La TVA applicable est ajoutée à la facturation.',
  },
  {
    id: 'annual',
    question: 'Existe-t-il une facturation annuelle ?',
    answer:
      'La grille commerciale actuelle est mensuelle. Aucun commutateur annuel n’est proposé tant qu’une offre annuelle n’est pas commercialisée.',
  },
  {
    id: 'packs',
    question: 'Comment fonctionnent les packs d’événements ?',
    answer:
      'Sur Starter et Pro, des packs sont définis au catalogue (+1 000, +5 000, +10 000, +50 000). L’achat self-serve via Stripe n’est pas encore ouvert à tous les comptes ; contactez-nous ou suivez les annonces dans l’application.',
  },
  {
    id: 'cancel',
    question: 'Puis-je résilier facilement ?',
    answer:
      'La gestion de l’abonnement s’effectue depuis l’espace facturation de l’application (portail Stripe lorsque disponible). Les modalités contractuelles figurent dans les CGV.',
  },
];
