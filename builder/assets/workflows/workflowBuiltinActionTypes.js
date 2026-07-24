/**
 * Miroir de App\FormWebhook\WorkflowBuiltinActionType (PHP).
 * Affichage / édition UI uniquement — l’exécution reste côté serveur.
 */

export const ACTION_AI = 'ai_action';
export const ACTION_GSC_FETCH = 'gsc_fetch';
export const ACTION_PARSE_JSON = 'parse_json';
export const ACTION_IF = 'if';

export const BUILTIN_ACTION_TYPES = [
  { id: ACTION_GSC_FETCH, label: 'Google Search Console — requêtes' },
  { id: ACTION_AI, label: 'IA — fournisseur de l’organisation' },
  { id: ACTION_PARSE_JSON, label: 'Parse JSON' },
  { id: ACTION_IF, label: 'Condition (saut d’étapes)' },
];

const BUILTIN_IDS = new Set(BUILTIN_ACTION_TYPES.map((t) => t.id));

export function isBuiltinActionType(actionType) {
  return BUILTIN_IDS.has(actionType);
}

export function builtinActionTypeLabel(actionType) {
  return BUILTIN_ACTION_TYPES.find((t) => t.id === actionType)?.label ?? actionType;
}
