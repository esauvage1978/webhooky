/** Constantes partagées workflows / actions. */
export const ACTION_MAILJET = 'mailjet';

export const DEFAULT_AI_PROMPT = `Tu es un assistant IA pour ce workflow Webhooky.

Message : {{message}}
E-mail : {{email}}
Contenu : {{content}}

Réponds de manière concise.`;

export const FW_ACTION_DRAG_MIME = 'application/x-fw-action-index';

export const FORM_WEBHOOK_AUDIT_ACTION_LABELS = {
  created: 'Création',
  updated: 'Mise à jour',
  deleted: 'Suppression',
};

export const FORM_WEBHOOK_AUDIT_KEY_LABELS = {
  name: 'Nom',
  description: 'Description',
  active: 'État actif / inactif',
  lifecycle: 'Brouillon / production',
  organizationId: 'Organisation',
  projectId: 'Projet',
  notificationEmailSource: 'Source de l’e-mail de notification',
  notificationCustomEmail: 'E-mail de notification (personnalisé)',
  notifyOnError: 'Notification en cas d’erreur',
  metadata: 'Métadonnées',
  actions: 'Actions du flux',
};

export const EDITOR_NAV = [
  { id: 'general', label: 'Général' },
  { id: 'notification', label: 'Notification' },
  { id: 'trigger', label: 'Déclencheur' },
  { id: 'actions', label: 'Actions' },
  { id: 'history', label: 'Historique' },
];
