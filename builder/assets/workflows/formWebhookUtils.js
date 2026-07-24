import { ACTION_MAILJET, DEFAULT_AI_PROMPT, FORM_WEBHOOK_AUDIT_KEY_LABELS } from './formWebhookConstants.js';
import { ACTION_AI, isBuiltinActionType } from './workflowBuiltinActionTypes.js';
import { isSmsActionType, typeLabelFromMeta } from '../integrations/serviceConnectionTypes.js';

export { isBuiltinActionType, isSmsActionType, ACTION_AI };

export function integrationTypeLabel(id, typesMeta = []) {
  return typeLabelFromMeta(typesMeta, id);
}

export function defaultAiPipelineConfig() {
  return {
    aiPromptTemplate: DEFAULT_AI_PROMPT,
    aiPromptId: '',
    aiPromptVariables: '{}',
    aiOutputKey: 'ai_response',
  };
}

export function webhookRowProjectId(row) {
  const v = row?.projectId ?? row?.project?.id;
  if (v == null || v === '') return null;
  return String(v);
}

export function webhookRowOrganizationId(row) {
  const v = row?.organizationId ?? row?.organization?.id;
  if (v == null || v === '') return null;
  return String(v);
}

export function resolveWebhookProjectLabel(row, projects) {
  if (row?.project?.name) return row.project.name;
  const pid = webhookRowProjectId(row);
  if (!pid) return '—';
  const p = projects.find((x) => String(x.id) === pid);
  if (p?.name) return p.name;
  return `Projet #${pid}`;
}

export function truncateMiddle(str, maxLen = 52) {
  const s = str == null ? '' : String(str);
  if (s.length <= maxLen) return s;
  const ellipsis = '…';
  const inner = maxLen - ellipsis.length;
  const left = Math.ceil(inner / 2);
  const right = Math.floor(inner / 2);
  return s.slice(0, left) + ellipsis + s.slice(-right);
}

export function formatWorkflowExecutionDate(iso) {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
}

export function webhookTestRequestBody() {
  return JSON.stringify({
    _webhooky_test: true,
    message: 'Test Webhooky',
    email: 'test@example.com',
    ts: new Date().toISOString(),
  });
}

/**
 * @returns {{ ok: boolean; message: string; detail?: string }}
 */
export async function runWebhookIngressTest(url) {
  if (url == null || String(url).trim() === '') {
    return { ok: false, message: 'URL manquante.' };
  }
  try {
    const res = await fetch(String(url).trim(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'omit',
      body: webhookTestRequestBody(),
    });
    const text = await res.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = null;
    }
    if (res.ok && data && data.ok === true) {
      const code = data.code ? String(data.code) : '';
      const skipped = data.skipped ? ' — traces uniquement (pas d’actions)' : '';
      const logId = data.logId != null ? ` · journal #${data.logId}` : '';
      return {
        ok: true,
        message: `Réponse OK${code ? ` (${code})` : ''}${skipped}${logId}`,
      };
    }
    if (data && typeof data.error === 'string') {
      return {
        ok: false,
        message: 'Le serveur a répondu avec une erreur.',
        detail: `${data.error} (HTTP ${res.status})`,
      };
    }
    return {
      ok: false,
      message: `HTTP ${res.status}`,
      detail: text ? text.slice(0, 280) : undefined,
    };
  } catch (e) {
    return { ok: false, message: e?.message || 'Erreur réseau (CORS ou URL inaccessible depuis le navigateur).' };
  }
}

export function lastExecutionVerifiedFromRow(row) {
  const v = row?.lastExecutionVerified;
  if (v === true || v === false) return v;
  const s = row?.lastLogStatus;
  if (s == null || s === '') return null;
  if (s === 'error') return false;
  if (s === 'sent' || s === 'skipped') return true;
  return false;
}

export function auditChangedFieldLabels(details) {
  if (!details || typeof details !== 'object') return [];
  if (Array.isArray(details.changedKeysLabels) && details.changedKeysLabels.length > 0) {
    return details.changedKeysLabels;
  }
  const keys = details.changedKeys;
  if (!Array.isArray(keys)) return [];
  return keys.filter((k) => typeof k === 'string').map((k) => FORM_WEBHOOK_AUDIT_KEY_LABELS[k] ?? k);
}

export function pickDefaultProjectId(projects, orgIdStr, isAdmin) {
  if (!orgIdStr) return '';
  const list = isAdmin
    ? projects.filter((p) => String(p.organizationId) === String(orgIdStr))
    : projects;
  const def = list.find((p) => p.isDefault) ?? list.find((p) => p.name === 'Général');
  if (def) return String(def.id);
  return list[0] ? String(list[0].id) : '';
}

export function newActionRowKey() {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `r-${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
}

export function actionBriefSummary(action, mailjetConnections, serviceConnections) {
  const at = action.actionType || ACTION_MAILJET;
  let line;
  if (at === ACTION_MAILJET) {
    const conn = action.serviceConnectionId
      ? mailjetConnections.find((m) => String(m.id) === String(action.serviceConnectionId))
      : null;
    const name =
      conn?.name ?? (action.mailjetId ? `compte legacy #${action.mailjetId}` : 'Connecteur ?');
    line = `Mailjet · ${name} · modèle #${action.mailjetTemplateId || '—'}`;
  } else if (at === ACTION_AI) {
    const key = String(action.aiOutputKey ?? '').trim() || 'last_ai_response';
    line = `IA · fournisseur de l’organisation · sortie {{data.${key}}}`;
  } else {
    const conn = serviceConnections.find((s) => String(s.id) === String(action.serviceConnectionId));
    line = `${integrationTypeLabel(at)} · ${conn?.name ?? 'Connecteur ?'}`;
  }
  if (action.active === false) line += ' · inactive';
  return line;
}

export function actionCardTitle(action, mailjetConnections, serviceConnections) {
  return actionBriefSummary(action, mailjetConnections, serviceConnections).replace(/\s*·\s*inactive\s*$/i, '').trim();
}

export function formatKvValue(v) {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'object') return JSON.stringify(v, null, 2);
  return String(v);
}

export function formatRawBodyForDisplay(body) {
  if (typeof body !== 'string' || body.trim() === '') {
    return typeof body === 'string' ? body : '';
  }
  const trimmed = body.trim();
  if (!(trimmed.startsWith('{') || trimmed.startsWith('['))) {
    return body;
  }
  try {
    return JSON.stringify(JSON.parse(trimmed), null, 2);
  } catch {
    return body;
  }
}

export function prettyJsonMaybe(raw) {
  if (raw == null || raw === '') return null;
  try {
    return JSON.stringify(JSON.parse(raw), null, 2);
  } catch {
    return raw;
  }
}

export function defaultActionRow(mailjetConnections = []) {
  return {
    _uiKey: newActionRowKey(),
    actionType: ACTION_MAILJET,
    mailjetId: '',
    mailjetTemplateId: '',
    templateLanguage: true,
    serviceConnectionId: mailjetConnections[0] ? String(mailjetConnections[0].id) : '',
    payloadTemplate: '',
    smsToPostKey: '',
    smsToDefault: '',
    variableMapping: '{\n  "var_modele": "champ_formulaire"\n}',
    recipientEmailPostKey: 'email',
    recipientNamePostKey: '',
    defaultRecipientEmail: '',
    comment: '',
    active: true,
    ...defaultAiPipelineConfig(),
  };
}

export function emptyForm() {
  return {
    name: '',
    description: '',
    organizationId: '',
    projectId: '',
    active: true,
    lifecycle: 'draft',
    notificationEmailSource: 'creator',
    notificationCustomEmail: '',
    notifyOnError: true,
    notificationCreatorHint: '',
    webhookVersion: null,
    actions: [defaultActionRow([])],
  };
}
