/**
 * Helpers partagés pour le catalogue de connecteurs (source : GET /api/service-connections/types).
 */

export function typeLabelFromMeta(typesMeta, id) {
  const hit = Array.isArray(typesMeta) ? typesMeta.find((t) => t.id === id) : null;
  return hit?.label ?? id;
}

/** Types utilisables comme action d’intégration (hors Mailjet, géré à part). */
export function integrationActionTypesFromMeta(typesMeta) {
  if (!Array.isArray(typesMeta)) return [];
  return typesMeta
    .filter((t) => t.id && t.id !== 'mailjet')
    .map((t) => ({ id: t.id, label: t.label ?? t.id }));
}

export function isSmsActionType(actionType, typesMeta = null) {
  if (!actionType) return false;
  if (Array.isArray(typesMeta)) {
    const hit = typesMeta.find((t) => t.id === actionType);
    if (hit && typeof hit.sms === 'boolean') return hit.sms;
  }
  return String(actionType).endsWith('_sms');
}

export function defaultConfigJson(type, typesMeta) {
  const meta = Array.isArray(typesMeta) ? typesMeta.find((t) => t.id === type) : null;
  if (meta?.configExampleFilled && Object.keys(meta.configExampleFilled).length > 0) {
    return JSON.stringify(meta.configExampleFilled, null, 2);
  }
  return JSON.stringify({ note: 'voir la documentation du type' }, null, 2);
}
