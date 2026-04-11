import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { parseJson } from '../lib/http.js';

/** Aligné sur App\ServiceIntegration\ServiceIntegrationType */
const ACTION_MAILJET = 'mailjet';

const INTEGRATION_ACTION_TYPES = [
  { id: 'slack', label: 'Slack (webhook)' },
  { id: 'teams', label: 'Microsoft Teams' },
  { id: 'discord', label: 'Discord' },
  { id: 'google_chat', label: 'Google Chat' },
  { id: 'mattermost', label: 'Mattermost' },
  { id: 'twilio_sms', label: 'Twilio SMS' },
  { id: 'vonage_sms', label: 'Vonage SMS' },
  { id: 'messagebird_sms', label: 'MessageBird SMS' },
  { id: 'telegram', label: 'Telegram' },
  { id: 'http_webhook', label: 'HTTP / Zapier / custom' },
  { id: 'pushover', label: 'Pushover' },
];

function integrationTypeLabel(id) {
  return INTEGRATION_ACTION_TYPES.find((t) => t.id === id)?.label ?? id;
}

function isSmsActionType(at) {
  return at === 'twilio_sms' || at === 'vonage_sms' || at === 'messagebird_sms';
}

/** Identifiant projet pour filtre / groupement (l’API peut exposer `projectId` et/ou `project.id`). */
function webhookRowProjectId(row) {
  const v = row?.projectId ?? row?.project?.id;
  if (v == null || v === '') return null;
  return String(v);
}

/** Organisation d’un workflow liste (aligné sur serialize API). */
function webhookRowOrganizationId(row) {
  const v = row?.organizationId ?? row?.organization?.id;
  if (v == null || v === '') return null;
  return String(v);
}

/** Libellé affichable du projet de rattachement. */
function resolveWebhookProjectLabel(row, projects) {
  if (row?.project?.name) return row.project.name;
  const pid = webhookRowProjectId(row);
  if (!pid) return '—';
  const p = projects.find((x) => String(x.id) === pid);
  if (p?.name) return p.name;
  return `Projet #${pid}`;
}

/** Affichage compact du type URLs longues sur les cartes liste. */
function truncateMiddle(str, maxLen = 52) {
  const s = str == null ? '' : String(str);
  if (s.length <= maxLen) return s;
  const ellipsis = '…';
  const inner = maxLen - ellipsis.length;
  const left = Math.ceil(inner / 2);
  const right = Math.floor(inner / 2);
  return s.slice(0, left) + ellipsis + s.slice(-right);
}

/**
 * Dernière exécution « correcte » (API `lastExecutionVerified`) ou repli sur `lastLogStatus`.
 *
 * @returns {boolean|null} true = vérifié, false = à vérifier, null = aucune exécution
 */
function lastExecutionVerifiedFromRow(row) {
  const v = row?.lastExecutionVerified;
  if (v === true || v === false) return v;
  const s = row?.lastLogStatus;
  if (s == null || s === '') return null;
  if (s === 'error') return false;
  if (s === 'sent' || s === 'skipped') return true;
  return false;
}

/**
 * Switch visuel (lecture seule) : position et libellés selon le dernier journal d’exécution.
 *
 * @param {{ verified: boolean|null; compact?: boolean }} props
 */
function LastExecutionVerifiedSwitch({ verified, compact = false }) {
  const ok = verified === true;
  const none = verified == null;
  const title = ok
    ? 'Dernière exécution : sans erreur enregistrée (vérifié).'
    : none
      ? 'Aucune exécution enregistrée pour l’instant.'
      : 'Dernière exécution : erreur — à vérifier.';
  const cls = ok ? 'fw-last-exec-switch--ok' : none ? 'fw-last-exec-switch--none' : 'fw-last-exec-switch--bad';
  return (
    <span className={`fw-last-exec-switch ${cls}`} role="status" title={title}>
      <span className="fw-last-exec-switch__track" aria-hidden="true">
        <span className="fw-last-exec-switch__thumb" />
      </span>
      {compact ? (
        <span className="fw-last-exec-switch__compact-label">{ok ? 'Vérifié' : none ? '—' : 'À vérifier'}</span>
      ) : (
        <span className="fw-last-exec-switch__labels">
          <span className={ok ? 'is-em' : 'muted'}>Vérifié</span>
          <span className="fw-last-exec-switch__sep" aria-hidden="true">
            /
          </span>
          <span className={!ok ? 'is-em' : 'muted'}>À vérifier</span>
        </span>
      )}
    </span>
  );
}

const FORM_WEBHOOK_AUDIT_ACTION_LABELS = {
  created: 'Création',
  updated: 'Mise à jour',
  deleted: 'Suppression',
};

const FORM_WEBHOOK_AUDIT_KEY_LABELS = {
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

function auditChangedFieldLabels(details) {
  if (!details || typeof details !== 'object') return [];
  if (Array.isArray(details.changedKeysLabels) && details.changedKeysLabels.length > 0) {
    return details.changedKeysLabels;
  }
  const keys = details.changedKeys;
  if (!Array.isArray(keys)) return [];
  return keys.filter((k) => typeof k === 'string').map((k) => FORM_WEBHOOK_AUDIT_KEY_LABELS[k] ?? k);
}

/** Détail avant / après renvoyé par l’API pour les mises à jour récentes. */
function WorkflowAuditDiff({ diff }) {
  if (!diff || typeof diff !== 'object' || !Array.isArray(diff.changes) || diff.changes.length === 0) {
    return null;
  }
  const dlGrid = {
    margin: '0.35rem 0 0',
    display: 'grid',
    gridTemplateColumns: '5.5rem 1fr',
    gap: '0.25rem 0.75rem',
    fontSize: '0.9rem',
  };
  const dd = { margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word' };

  return (
    <div className="fw-audit-diff" style={{ marginTop: '0.75rem' }}>
      <div className="fw-audit-diff-title" style={{ fontWeight: 600 }}>
        Détail des changements
      </div>
      {diff.changes.map((block, bi) => {
        if (!block || typeof block !== 'object') return null;
        if (block.kind === 'scalar') {
          return (
            <div key={`s-${block.key}-${bi}`} className="fw-audit-diff-block" style={{ marginTop: '0.6rem' }}>
              <div style={{ fontWeight: 600 }}>{block.label ?? block.key}</div>
              <dl className="fw-audit-diff-dl" style={dlGrid}>
                <dt className="muted">Avant</dt>
                <dd style={dd}>{block.beforeDisplay ?? '—'}</dd>
                <dt className="muted">Après</dt>
                <dd style={dd}>{block.afterDisplay ?? '—'}</dd>
              </dl>
            </div>
          );
        }
        if (block.kind === 'actions' && Array.isArray(block.items)) {
          return (
            <div key={`a-${bi}`} className="fw-audit-diff-block" style={{ marginTop: '0.75rem' }}>
              <div style={{ fontWeight: 600 }}>{block.label ?? 'Actions du flux'}</div>
              <ul style={{ margin: '0.5rem 0 0', paddingLeft: '1.1rem' }}>
                {block.items.map((item, ii) => (
                  <li key={`act-${item.slot}-${item.changeType}-${ii}`} style={{ marginBottom: '0.65rem' }}>
                    <strong>Action {item.slot}</strong>
                    {item.changeTypeLabel ? (
                      <>
                        {' '}
                        <span className="muted">({item.changeTypeLabel})</span>
                      </>
                    ) : null}
                    {Array.isArray(item.fieldChanges) && item.fieldChanges.length > 0 ? (
                      <ul style={{ margin: '0.35rem 0 0', paddingLeft: '1rem', listStyle: 'disc' }}>
                        {item.fieldChanges.map((fc, fi) => (
                          <li key={`${fc.key}-${fi}`} style={{ marginBottom: '0.35rem' }}>
                            <span style={{ fontWeight: 500 }}>{fc.label || fc.key}</span>
                            <dl style={{ ...dlGrid, fontSize: '0.88rem', margin: '0.2rem 0 0' }}>
                              <dt className="muted">Avant</dt>
                              <dd style={dd}>{fc.beforeDisplay ?? '—'}</dd>
                              <dt className="muted">Après</dt>
                              <dd style={dd}>{fc.afterDisplay ?? '—'}</dd>
                            </dl>
                          </li>
                        ))}
                      </ul>
                    ) : null}
                  </li>
                ))}
              </ul>
            </div>
          );
        }
        return null;
      })}
    </div>
  );
}

/** Une ligne d’historique de version (GET …/audit). */
function WorkflowAuditEntry({ entry }) {
  const actionLabel = FORM_WEBHOOK_AUDIT_ACTION_LABELS[entry.action] ?? entry.action;
  const d = entry.details && typeof entry.details === 'object' ? entry.details : {};
  const changeLabels = auditChangedFieldLabels(d);

  return (
    <>
      <div>
        <strong>{actionLabel}</strong>
        {' · '}
        {entry.occurredAt
          ? new Date(entry.occurredAt).toLocaleString('fr-FR', {
              dateStyle: 'short',
              timeStyle: 'medium',
            })
          : '—'}
      </div>
      <div className="muted small">{entry.actorEmail ?? '—'}</div>
      {typeof d.auditSummary === 'string' && d.auditSummary ? (
        <p className="fw-audit-summary">{d.auditSummary}</p>
      ) : null}
      {changeLabels.length > 0 ? (
        <ul className="fw-audit-change-list">
          {changeLabels.map((label, idx) => (
            <li key={`${label}-${idx}`}>{label}</li>
          ))}
        </ul>
      ) : null}
      {entry.action === 'updated' ? <WorkflowAuditDiff diff={d.diff} /> : null}
      {entry.action === 'updated' && d.version != null && d.previousVersion != null ? (
        <div className="muted small fw-audit-version-line">
 v.{d.previousVersion} → v.{d.version}
        </div>
      ) : entry.action !== 'updated' && d.version != null ? (
        <div className="muted small fw-audit-version-line">Version {d.version}</div>
      ) : null}
    </>
  );
}

/**
 * Menu actions (un seul menu ouvert : compare `menuOpen` à `menuKey`).
 * @param {object} props
 * @param {string|number} props.menuKey
 * @param {string|number|null} props.menuOpen
 * @param {(id: string|number|null) => void} props.onMenuOpenChange
 */
function WebhookActionsMenu({
  menuKey,
  menuOpen,
  onMenuOpenChange,
  triggerVariant = 'dots',
  showFiche = true,
  onFiche,
  onLogs,
  onEdit,
  onDuplicate,
  onDelete,
  duplicateDisabled = false,
  duplicateTitle,
}) {
  const open = menuOpen === menuKey;
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        onMenuOpenChange(null);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') onMenuOpenChange(null);
    };
    document.addEventListener('click', onDoc, true);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('click', onDoc, true);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, onMenuOpenChange]);

  const close = () => onMenuOpenChange(null);

  const isDots = triggerVariant === 'dots';

  return (
    <div className="fw-card-menu-wrap" ref={wrapRef}>
      <button
        type="button"
        className={`fw-card-menu-trigger${isDots ? ' fw-card-menu-trigger--dots' : ''}`}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={isDots ? 'Plus d’actions' : undefined}
        onClick={(e) => {
          e.stopPropagation();
          onMenuOpenChange(open ? null : menuKey);
        }}
      >
        {isDots ? (
          <span className="fw-card-menu-dots" aria-hidden>
            ⋯
          </span>
        ) : (
          <>
            Actions
            <span className="fw-card-menu-chevron" aria-hidden>
              ▾
            </span>
          </>
        )}
      </button>
      {open ? (
        <ul className="fw-card-menu-dropdown" role="menu">
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              onClick={() => {
                close();
                onEdit?.();
              }}
            >
              Éditer
            </button>
          </li>
          {showFiche ? (
            <li role="none">
              <button
                type="button"
                className="fw-card-menu-item"
                role="menuitem"
                onClick={() => {
                  close();
                  onFiche?.();
                }}
              >
                Fiche
              </button>
            </li>
          ) : null}
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              onClick={() => {
                close();
                onLogs?.();
              }}
            >
              Journaux
            </button>
          </li>
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              disabled={duplicateDisabled}
              title={duplicateTitle}
              onClick={() => {
                if (duplicateDisabled) return;
                close();
                onDuplicate?.();
              }}
            >
              Dupliquer
            </button>
          </li>
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item fw-card-menu-item--danger"
              role="menuitem"
              onClick={() => {
                close();
                onDelete?.();
              }}
            >
              Supprimer
            </button>
          </li>
        </ul>
      ) : null}
    </div>
  );
}

/** @param {object[]} projects @param {string} orgIdStr @param {boolean} isAdmin */
function pickDefaultProjectId(projects, orgIdStr, isAdmin) {
  if (!orgIdStr) return '';
  const list = isAdmin
    ? projects.filter((p) => String(p.organizationId) === String(orgIdStr))
    : projects;
  const def = list.find((p) => p.isDefault) ?? list.find((p) => p.name === 'Général');
  if (def) return String(def.id);
  return list[0] ? String(list[0].id) : '';
}

function newActionRowKey() {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `r-${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
}

function actionBriefSummary(action, mailjetConnections, serviceConnections) {
  const at = action.actionType || ACTION_MAILJET;
  let line;
  if (at === ACTION_MAILJET) {
    const conn = action.serviceConnectionId
      ? mailjetConnections.find((m) => String(m.id) === String(action.serviceConnectionId))
      : null;
    const name =
      conn?.name ?? (action.mailjetId ? `compte legacy #${action.mailjetId}` : 'Connecteur ?');
    line = `Mailjet · ${name} · modèle #${action.mailjetTemplateId || '—'}`;
  } else {
    const conn = serviceConnections.find((s) => String(s.id) === String(action.serviceConnectionId));
    line = `${integrationTypeLabel(at)} · ${conn?.name ?? 'Connecteur ?'}`;
  }
  if (action.active === false) line += ' · inactive';
  return line;
}

/** Titre visible sur la carte (sans le suffixe « inactive », géré par l’interrupteur). */
function actionCardTitle(action, mailjetConnections, serviceConnections) {
  return actionBriefSummary(action, mailjetConnections, serviceConnections).replace(/\s*·\s*inactive\s*$/i, '').trim();
}

function formatKvValue(v) {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'object') return JSON.stringify(v, null, 2);
  return String(v);
}

function KeyValueBlock({ title, record, emptyText }) {
  const entries =
    record && typeof record === 'object' && !Array.isArray(record) ? Object.entries(record) : [];
  if (entries.length === 0) {
    return (
      <div className="log-kv-block">
        <h4 className="log-kv-title">{title}</h4>
        <p className="muted small">{emptyText ?? 'Aucune donnée'}</p>
      </div>
    );
  }
  return (
    <div className="log-kv-block">
      <h4 className="log-kv-title">{title}</h4>
      <dl className="log-kv-grid">
        {entries.map(([k, v]) => {
          const display = formatKvValue(v);
          const multiline = display.includes('\n') || display.length > 120;
          return (
            <Fragment key={k}>
              <dt className="mono">{k}</dt>
              <dd className={multiline ? 'log-kv-dd-pre' : ''}>
                {multiline ? <pre className="log-kv-pre">{display}</pre> : display}
              </dd>
            </Fragment>
          );
        })}
      </dl>
    </div>
  );
}

function prettyJsonMaybe(raw) {
  if (raw == null || raw === '') return null;
  try {
    return JSON.stringify(JSON.parse(raw), null, 2);
  } catch {
    return raw;
  }
}

const FW_ACTION_DRAG_MIME = 'application/x-fw-action-index';

/** Interrupteur visuel (orange quand actif), accessibilité role="switch". */
function FwSwitch({ id, checked, onChange, label, disabled }) {
  const labelId = id ? `${id}-label` : undefined;
  return (
    <div className={`fw-switch-row${disabled ? ' is-disabled' : ''}`}>
      {label ? (
        <span className="fw-switch-label" id={labelId}>
          {label}
        </span>
      ) : null}
      <button
        type="button"
        id={id}
        className={`fw-switch-track${checked ? ' is-on' : ''}`}
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        aria-labelledby={labelId}
        onClick={() => !disabled && onChange(!checked)}
      >
        <span className="fw-switch-thumb" aria-hidden />
      </button>
    </div>
  );
}

/** Fiche /workflows/:id — préférences de notification (récaps d’erreur) */
function WebhookDetailNotificationsPanel({ webhook }) {
  const d = webhook?.notificationDiagnostics;

  if (!d) {
    return (
      <section className="fw-detail-notify" aria-labelledby="fw-notify-h">
        <h3 id="fw-notify-h" className="fw-detail-section-title">
          Notifications e-mail
        </h3>
        <p className="muted small" style={{ marginTop: 0 }}>
          Rechargez la fiche pour afficher les préférences (récapitulatif en cas d’échec d’exécution).
        </p>
      </section>
    );
  }

  const srcLabel =
    d.notificationEmailSource === 'custom'
      ? 'Adresse personnalisée (champ du workflow)'
      : 'E-mail du créateur du workflow';

  return (
    <section className="fw-detail-notify" aria-labelledby="fw-notify-h">
      <h3 id="fw-notify-h" className="fw-detail-section-title">
        Notifications e-mail
      </h3>
      <p className="fw-detail-notify-lead">
        En cas d’échec du workflow, un récapitulatif peut être envoyé au destinataire configuré. Réglez les options dans{' '}
        <strong>Modifier</strong>.
      </p>

      {d.notifyOnError && d.recipientBlockedReason ? (
        <p className="fw-notify-warn">
          <strong>Destinataire :</strong> {d.recipientBlockedReason} — corrigez dans <strong>Modifier</strong> pour
          recevoir les récaps d’erreur.
        </p>
      ) : null}

      <dl className="fw-notify-dl">
        <dt>Destinataire pour les récaps d’erreur</dt>
        <dd className="mono">{d.effectiveRecipientEmail ?? '—'}</dd>
        <dt>Source</dt>
        <dd>{srcLabel}</dd>
        <dt>Notifier si erreur</dt>
        <dd>{d.notifyOnError ? 'Oui' : 'Non'}</dd>
        <dt>Envoi en cas d’échec (si destinataire OK)</dt>
        <dd>
          {d.willSendErrorEmailWhenRecipientOk
            ? 'Oui, lorsqu’au moins une action échoue'
            : d.notifyOnError
              ? 'Non tant que le destinataire est invalide'
              : 'Non (option désactivée)'}
        </dd>
      </dl>
    </section>
  );
}

/** Schéma vertical type automate : déclencheur → actions */
function WebhookFlowDiagram({ actions }) {
  const list = Array.isArray(actions) ? actions : [];
  return (
    <div className="fw-diagram">
      <div className="fw-d-node fw-d-node-trigger">
        <span className="fw-d-dot" aria-hidden />
        <div className="fw-d-text">
          <strong>Déclencheur</strong>
          <span>Réception POST (formulaire ou JSON)</span>
        </div>
      </div>
      {list.length > 0 ? <div className="fw-d-link" aria-hidden /> : null}
      {list.length === 0 ? (
        <p className="fw-d-empty">Aucune action configurée</p>
      ) : (
        list.map((a, i) => (
          <Fragment key={i}>
            <div className="fw-d-node">
              <span className="fw-d-dot fw-d-dot-action" aria-hidden />
              <div className="fw-d-text">
                <strong>
                  Action {i + 1} — {a.actionTypeLabel || (a.actionType === ACTION_MAILJET ? 'Mailjet' : a.actionType)}
                </strong>
                <span>
                  {a.actionType === ACTION_MAILJET || !a.actionType
                    ? a.serviceConnectionName
                      ? `Mailjet · ${a.serviceConnectionName} · modèle #${a.mailjetTemplateId}`
                      : `Template Mailjet #${a.mailjetTemplateId}`
                    : a.serviceConnectionName
                      ? `Connecteur « ${a.serviceConnectionName} »`
                      : `Connecteur #${a.serviceConnectionId ?? '—'}`}
                  {a.active === false ? ' · inactive' : ''}
                </span>
              </div>
            </div>
            {i < list.length - 1 ? <div className="fw-d-link" aria-hidden /> : null}
          </Fragment>
        ))
      )}
    </div>
  );
}

function ActionFields({
  action,
  index,
  mailjetConnections,
  serviceConnections,
  vendorUrlByType,
  updateAction,
  onRemove,
  canRemove,
  totalActions,
  dragIndex,
  onDragHandleStart,
  onDragHandleEnd,
  isDragging,
}) {
  const at = action.actionType || ACTION_MAILJET;
  const connectionsForType = serviceConnections.filter((s) => s.type === at);
  const [expanded, setExpanded] = useState(() => totalActions === 1);
  useEffect(() => {
    setExpanded(totalActions === 1);
  }, [totalActions]);
  const bodyId = `fw-action-body-${action._uiKey ?? index}`;
  const execSwitchId = `fw-action-exec-${action._uiKey ?? index}`;
  const tplSwitchId = `fw-action-tpl-${action._uiKey ?? index}`;
  const titleLine = actionCardTitle(action, mailjetConnections, serviceConnections);

  return (
    <fieldset
      className={`fw-action-card fw-action-card--editor mailjet-form${expanded ? ' is-expanded' : ''}${
        isDragging ? ' is-dragging' : ''
      }`}
    >
      <legend className="fw-sr-only">
        Action {index + 1} sur {totalActions} — {titleLine} — ordre {index + 1}
      </legend>
      <div className="fw-action-toolbar fw-action-toolbar--editor">
        <div
          className="fw-action-drag-handle"
          draggable
          onDragStart={(e) => {
            const s = String(dragIndex);
            e.dataTransfer.setData(FW_ACTION_DRAG_MIME, s);
            try {
              e.dataTransfer.setData('text/plain', s);
            } catch {
              /* ignore */
            }
            e.dataTransfer.effectAllowed = 'move';
            onDragHandleStart?.();
          }}
          onDragEnd={() => onDragHandleEnd?.()}
          title="Glisser-déposer pour réordonner"
          aria-label="Réordonner l’action par glisser-déposer"
          role="presentation"
        >
          <span className="fw-action-drag-grip" aria-hidden />
        </div>
        <button
          type="button"
          className="btn secondary small fw-action-toggle"
          aria-expanded={expanded}
          aria-controls={bodyId}
          onClick={() => setExpanded((e) => !e)}
        >
          {expanded ? '▼ Réduire' : '▶ Détail'}
        </button>
        <div className="fw-action-toolbar-main">
          <div className="fw-action-toolbar-titles">
            <span className="fw-action-heading-primary" title={titleLine}>
              {titleLine}
            </span>
            <span className="fw-action-order-hint">
              Action {index + 1} · Étape {index + 1} / {totalActions}
            </span>
          </div>
          <FwSwitch
            id={execSwitchId}
            checked={action.active !== false}
            onChange={(v) => updateAction(index, { active: v })}
            label="Exécuter cette action à chaque réception"
          />
        </div>
        {canRemove ? (
          <button type="button" className="btn secondary small fw-action-remove" onClick={() => onRemove(index)}>
            Retirer
          </button>
        ) : (
          <span className="fw-action-remove-placeholder" />
        )}
      </div>
      <div id={bodyId} className="fw-action-body fw-action-body--stack" hidden={!expanded}>
        <div className="fw-action-group fw-action-group--comment">
          <h4 className="fw-action-group-title">Commentaire</h4>
          <div className="fw-action-group-fields fw-action-group-fields--single">
            <label className="field">
              <span>Notes internes (équipe)</span>
              <textarea
                rows={3}
                className="fw-action-comment-textarea"
                value={action.comment ?? ''}
                maxLength={4000}
                onChange={(e) => updateAction(index, { comment: e.target.value })}
                placeholder="Optionnel — non utilisé à l’exécution du workflow, uniquement pour vous retrouver entre actions."
                spellCheck={true}
              />
            </label>
            <p className="muted small fw-action-group-note" style={{ marginTop: '0.35rem' }}>
              Maximum 4000 caractères. Stocké avec l’action en base de données.
            </p>
          </div>
        </div>
        <div className="fw-action-group fw-action-group--type">
          <h4 className="fw-action-group-title">Type et liaison</h4>
          <div className="fw-action-group-fields fw-action-group-fields--2col">
            <label className="field">
              <span>Type d’action</span>
              <select
                value={at}
                onChange={(e) => {
                  const t = e.target.value;
                  updateAction(index, {
                    actionType: t,
                    serviceConnectionId:
                      t === ACTION_MAILJET
                        ? mailjetConnections[0]
                          ? String(mailjetConnections[0].id)
                          : ''
                        : '',
                    mailjetId: '',
                  });
                }}
              >
                <option value={ACTION_MAILJET}>Mailjet — e-mail (template)</option>
                {INTEGRATION_ACTION_TYPES.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.label}
                  </option>
                ))}
              </select>
              {vendorUrlByType?.[at] ? (
                <span className="muted small" style={{ display: 'block', marginTop: '0.35rem' }}>
                  <a
                    href={vendorUrlByType[at]}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="sc-vendor-link"
                  >
                    Site du fournisseur (nouvel onglet)
                  </a>
                </span>
              ) : null}
            </label>

            {at === ACTION_MAILJET ? (
              <>
                <label className="field">
                  <span>Connecteur Mailjet (Intégrations)</span>
                  <select
                    value={action.serviceConnectionId}
                    onChange={(e) => updateAction(index, { serviceConnectionId: e.target.value, mailjetId: '' })}
                    required
                  >
                    <option value="">— Choisir —</option>
                    {mailjetConnections.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name} (#{m.id})
                      </option>
                    ))}
                  </select>
                </label>
                {mailjetConnections.length === 0 ? (
                  <p className="muted small fw-action-group-note">
                    Créez d’abord un connecteur <strong>Mailjet</strong> dans <strong>Intégrations</strong>.
                  </p>
                ) : null}
                {action.mailjetId && !action.serviceConnectionId ? (
                  <p className="muted small fw-action-group-note">
                    Référence ancienne table Mailjet (#{action.mailjetId}) : sélectionnez le connecteur équivalent après
                    migration.
                  </p>
                ) : null}
              </>
            ) : (
              <>
                <label className="field">
                  <span>Connecteur enregistré (section Intégrations)</span>
                  <select
                    value={action.serviceConnectionId}
                    onChange={(e) => updateAction(index, { serviceConnectionId: e.target.value })}
                    required
                  >
                    <option value="">— Choisir —</option>
                    {connectionsForType.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name} (#{s.id})
                      </option>
                    ))}
                  </select>
                </label>
                {connectionsForType.length === 0 ? (
                  <p className="muted small fw-action-group-note">
                    Créez d’abord un connecteur de ce type dans <strong>Intégrations</strong>.
                  </p>
                ) : null}
              </>
            )}
          </div>
        </div>

        {at === ACTION_MAILJET ? (
          <div className="fw-action-group fw-action-group--template">
            <h4 className="fw-action-group-title">Modèle Mailjet</h4>
            <div className="fw-action-group-fields fw-action-group-fields--2col">
              <label className="field">
                <span>ID template Mailjet</span>
                <input
                  type="number"
                  value={action.mailjetTemplateId}
                  onChange={(e) => updateAction(index, { mailjetTemplateId: e.target.value })}
                  required
                />
              </label>
              <div className="field fw-field--switch-only">
                <FwSwitch
                  id={tplSwitchId}
                  checked={!!action.templateLanguage}
                  onChange={(v) => updateAction(index, { templateLanguage: v })}
                  label="Template language (MJML / dynamique)"
                />
              </div>
            </div>
          </div>
        ) : (
          <div className="fw-action-group fw-action-group--message">
            <h4 className="fw-action-group-title">Message</h4>
            <div className="fw-action-group-fields fw-action-group-fields--2col">
              <label className="field fw-field--full">
                <span>Message ou corps (optionnel, placeholders &#123;&#123; champ &#125;&#125;)</span>
                <textarea
                  rows={4}
                  value={action.payloadTemplate}
                  onChange={(e) => updateAction(index, { payloadTemplate: e.target.value })}
                  placeholder="Ex. Nouvelle demande : {{email}}"
                  spellCheck={false}
                />
              </label>
              {at === 'http_webhook' ? (
                <p className="muted small fw-action-group-note">
                  URL et méthode : connecteur. Ici : corps JSON optionnel avec placeholders ou laisser vide pour un objet
                  par défaut.
                </p>
              ) : null}
            </div>
          </div>
        )}

        {at === ACTION_MAILJET ? (
          <div className="fw-action-group fw-action-group--recipients">
            <h4 className="fw-action-group-title">Destinataires</h4>
            <div className="fw-action-group-fields fw-action-group-fields--2col">
              <label className="field">
                <span>Champ POST e-mail destinataire</span>
                <input
                  value={action.recipientEmailPostKey}
                  onChange={(e) => updateAction(index, { recipientEmailPostKey: e.target.value })}
                  placeholder="email"
                />
              </label>
              <label className="field">
                <span>Champ POST nom destinataire</span>
                <input
                  value={action.recipientNamePostKey}
                  onChange={(e) => updateAction(index, { recipientNamePostKey: e.target.value })}
                />
              </label>
              <label className="field">
                <span>E-mail destinataire par défaut</span>
                <input
                  type="email"
                  value={action.defaultRecipientEmail}
                  onChange={(e) => updateAction(index, { defaultRecipientEmail: e.target.value })}
                />
              </label>
            </div>
          </div>
        ) : isSmsActionType(at) ? (
          <div className="fw-action-group fw-action-group--sms">
            <h4 className="fw-action-group-title">SMS (destinataire)</h4>
            <div className="fw-action-group-fields fw-action-group-fields--2col">
              <label className="field">
                <span>Champ POST du numéro destinataire (E.164)</span>
                <input
                  value={action.smsToPostKey}
                  onChange={(e) => updateAction(index, { smsToPostKey: e.target.value })}
                  placeholder="phone"
                />
              </label>
              <label className="field">
                <span>Numéro par défaut (si champ absent)</span>
                <input
                  value={action.smsToDefault}
                  onChange={(e) => updateAction(index, { smsToDefault: e.target.value })}
                  placeholder="+33…"
                />
              </label>
            </div>
          </div>
        ) : null}

        <div className="fw-action-group fw-action-group--mapping">
          <h4 className="fw-action-group-title">Mapping des champs</h4>
          <div className="fw-action-group-fields fw-action-group-fields--single">
            <label className="field">
              <span>Mapping JSON — variable / placeholder → champ POST (source)</span>
              <textarea
                rows={4}
                className="mono-textarea"
                value={action.variableMapping}
                onChange={(e) => updateAction(index, { variableMapping: e.target.value })}
                spellCheck={false}
              />
            </label>
          </div>
        </div>
      </div>
    </fieldset>
  );
}

function defaultActionRow(mailjetConnections = []) {
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
  };
}

const emptyForm = {
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

/** Navigation latérale de l’éditeur workflow (ancres + libellés). */
const EDITOR_NAV = [
  { id: 'general', label: 'Général' },
  { id: 'notification', label: 'Notification' },
  { id: 'trigger', label: 'Déclencheur' },
  { id: 'actions', label: 'Actions' },
  { id: 'history', label: 'Historique' },
];

/**
 * Routes : /workflows | /workflows/:id | /workflows/:id/edit | /workflows/:id/logs
 * @param {{ user: object; route: object; onWebhooksNavigate: (r: object) => void; onAppNavigate?: (navId: string) => void }} props
 */
/** `list` | `editor` | `logs` */
export default function FormWebhooks({ user, route, onWebhooksNavigate, onAppNavigate }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [items, setItems] = useState([]);
  const [serviceConnections, setServiceConnections] = useState([]);
  const [serviceConnectionTypesMeta, setServiceConnectionTypesMeta] = useState([]);
  const [orgs, setOrgs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [view, setView] = useState('list');
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  /** Aligné sur setSaving(saving) : évite qu’un fetch route async appelle fillEditForm pendant l’enregistrement. */
  const savingRef = useRef(false);
  /** Message transitoire après enregistrement réussi en édition (on reste sur l’éditeur). */
  const [saveSuccessMessage, setSaveSuccessMessage] = useState('');
  const [logsWebhookId, setLogsWebhookId] = useState(null);
  const [logsWebhookName, setLogsWebhookName] = useState('');
  const [logs, setLogs] = useState(null);
  const [logDetail, setLogDetail] = useState(null);
  const [selectedLogId, setSelectedLogId] = useState(null);
  const [logDetailLoading, setLogDetailLoading] = useState(false);
  const [logDetailError, setLogDetailError] = useState('');
  /** Fiche lecture seule /workflows/:id */
  const [detailWebhook, setDetailWebhook] = useState(null);
  const [detailTab, setDetailTab] = useState('overview');

  const [projects, setProjects] = useState([]);
  const [projectsReady, setProjectsReady] = useState(false);
  const [projectFilter, setProjectFilter] = useState('all');
  const [listSearchQuery, setListSearchQuery] = useState('');
  /** Poignée glisser-déposer : clé `_uiKey` de l’action en cours de déplacement. */
  const [actionDraggingKey, setActionDraggingKey] = useState(null);
  /** Menu Actions ouvert sur une carte liste (id webhook), ou null. */
  const [webhookCardMenuOpenId, setWebhookCardMenuOpenId] = useState(null);
  /** Même chose sur la fiche détail (même composant, état séparé). */
  const [detailActionsMenuOpenId, setDetailActionsMenuOpenId] = useState(null);
  /** Section mise en avant dans le menu latéral de l’éditeur. */
  const [editorNavSection, setEditorNavSection] = useState('general');
  /** Évite de repasser sur l’onglet « Général » après une sauvegarde (même workflow) — sinon les actions semblent « disparaître ». */
  const editorNavContextRef = useRef({ view: null, editingId: null });
  /** Journal d’audit (versions / modifications) pour l’éditeur et la fiche. */
  const [webhookAudit, setWebhookAudit] = useState(null);
  const [webhookAuditLoading, setWebhookAuditLoading] = useState(false);
  const [webhookAuditError, setWebhookAuditError] = useState('');
  const [detailWebhookAudit, setDetailWebhookAudit] = useState(null);
  const [detailWebhookAuditLoading, setDetailWebhookAuditLoading] = useState(false);

  const loadProjects = useCallback(async () => {
    setProjectsReady(false);
    try {
      const res = await fetch('/api/webhook-projects', { credentials: 'include' });
      const data = await parseJson(res);
      if (res.ok && Array.isArray(data)) setProjects(data);
      else setProjects([]);
    } catch {
      setProjects([]);
    } finally {
      setProjectsReady(true);
    }
  }, []);

  const loadRefs = useCallback(async () => {
    const [rSc, rTypes, rOrg] = await Promise.all([
      fetch('/api/service-connections', { credentials: 'include' }),
      fetch('/api/service-connections/types', { credentials: 'include' }),
      isAdmin ? fetch('/api/organizations', { credentials: 'include' }) : Promise.resolve(null),
    ]);
    const dSc = await parseJson(rSc);
    if (rSc.ok && Array.isArray(dSc)) setServiceConnections(dSc);
    const dTypes = await parseJson(rTypes);
    if (rTypes.ok && dTypes?.types) setServiceConnectionTypesMeta(dTypes.types);
    if (isAdmin && rOrg) {
      const dO = await parseJson(rOrg);
      if (rOrg.ok && Array.isArray(dO)) setOrgs(dO);
    }
  }, [isAdmin]);

  const integrationVendorUrlByType = useMemo(() => {
    const m = {};
    for (const t of serviceConnectionTypesMeta) {
      if (t.vendorUrl) m[t.id] = t.vendorUrl;
    }
    return m;
  }, [serviceConnectionTypesMeta]);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch('/api/form-webhooks', { credentials: 'include' });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Erreur');
        setItems([]);
        return;
      }
      setItems(Array.isArray(data) ? data : []);
    } catch {
      setError('Erreur réseau');
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadRefs();
  }, [loadRefs]);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  useEffect(() => {
    setWebhookCardMenuOpenId(null);
    setDetailActionsMenuOpenId(null);
  }, [view]);

  useEffect(() => {
    if (view !== 'editor') {
      editorNavContextRef.current = { view, editingId };
      return;
    }
    const prev = editorNavContextRef.current;
    const enteredEditor = prev.view !== 'editor';
    const changedWebhook =
      prev.view === 'editor' &&
      editingId !== 'new' &&
      prev.editingId !== 'new' &&
      Number(prev.editingId) !== Number(editingId);
    const leftNewDraft =
      prev.view === 'editor' &&
      prev.editingId === 'new' &&
      editingId !== 'new' &&
      editingId != null;
    if (enteredEditor || changedWebhook || leftNewDraft) {
      setEditorNavSection('general');
    }
    editorNavContextRef.current = { view, editingId };
  }, [view, editingId]);

  useEffect(() => {
    if (editingId === 'new' && editorNavSection === 'history') {
      setEditorNavSection('general');
    }
  }, [editingId, editorNavSection]);

  useEffect(() => {
    if (view !== 'editor' || editorNavSection !== 'history' || editingId === 'new' || editingId == null) {
      return;
    }
    let cancelled = false;
    setWebhookAuditLoading(true);
    setWebhookAuditError('');
    void (async () => {
      try {
        const res = await fetch(`/api/form-webhooks/${editingId}/audit`, { credentials: 'include' });
        const data = await parseJson(res);
        if (cancelled) return;
        if (!res.ok) {
          setWebhookAuditError(data?.error ?? 'Erreur');
          setWebhookAudit(null);
          return;
        }
        setWebhookAudit(data);
      } catch {
        if (!cancelled) {
          setWebhookAuditError('Erreur réseau');
          setWebhookAudit(null);
        }
      } finally {
        if (!cancelled) setWebhookAuditLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [view, editorNavSection, editingId]);

  useEffect(() => {
    if (view !== 'detail' || detailWebhook?.id == null) {
      setDetailWebhookAudit(null);
      return;
    }
    const wid = detailWebhook.id;
    let cancelled = false;
    setDetailWebhookAuditLoading(true);
    void (async () => {
      try {
        const res = await fetch(`/api/form-webhooks/${wid}/audit`, { credentials: 'include' });
        const data = await parseJson(res);
        if (cancelled) return;
        if (res.ok) setDetailWebhookAudit(data);
        else setDetailWebhookAudit(null);
      } catch {
        if (!cancelled) setDetailWebhookAudit(null);
      } finally {
        if (!cancelled) setDetailWebhookAuditLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [view, detailWebhook?.id]);

  useEffect(() => {
    setDetailTab('overview');
  }, [detailWebhook?.id]);

  /** Hors administrateur : projets de l’organisation active uniquement (organizationId fourni par l’API). */
  const projectsScoped = useMemo(() => {
    if (isAdmin) return projects;
    const oid = user.organization?.id;
    if (oid == null) return [];
    return projects.filter((p) => p.organizationId != null && String(p.organizationId) === String(oid));
  }, [isAdmin, projects, user.organization?.id]);

  useEffect(() => {
    const projectPool = isAdmin ? projects : projectsScoped;
    if (view !== 'editor' || projectPool.length === 0) return;
    setForm((f) => {
      const oid = isAdmin ? f.organizationId : user.organization?.id != null ? String(user.organization.id) : '';
      if (!oid) return f;
      const plist = isAdmin ? projects.filter((p) => String(p.organizationId) === String(oid)) : projectsScoped;
      const ok = f.projectId && plist.some((p) => String(p.id) === String(f.projectId));
      if (ok) return f;
      const pid = pickDefaultProjectId(isAdmin ? projects : projectsScoped, oid, isAdmin);
      return pid ? { ...f, projectId: pid } : f;
    });
  }, [view, projects, projectsScoped, isAdmin, user.organization, form.organizationId]);

  /** Projets de l’organisation active (pour les admins, filtrés sur le contexte de travail). */
  const projectsForActiveOrg = useMemo(() => {
    if (!isAdmin) return projectsScoped;
    const oid = user.organization?.id;
    if (oid == null) return projects;
    return projects.filter((p) => String(p.organizationId) === String(oid));
  }, [isAdmin, projects, projectsScoped, user.organization?.id]);

  const missingProjectsBlock = projectsReady && projectsForActiveOrg.length === 0;

  /** Hors administrateur : uniquement les connecteurs de l’organisation active (aligné API + garde UI). */
  const serviceConnectionsScoped = useMemo(() => {
    if (isAdmin) return serviceConnections;
    const oid = user.organization?.id;
    if (oid == null) return [];
    return serviceConnections.filter(
      (s) => s.organizationId != null && String(s.organizationId) === String(oid),
    );
  }, [isAdmin, serviceConnections, user.organization?.id]);

  const mailjetConnections = useMemo(
    () => serviceConnectionsScoped.filter((s) => s.type === ACTION_MAILJET),
    [serviceConnectionsScoped],
  );

  const projectsForEditor = useMemo(() => {
    if (!isAdmin) return projectsScoped;
    const oid = form.organizationId;
    if (!oid) return [];
    return projects.filter((p) => String(p.organizationId) === String(oid));
  }, [isAdmin, projects, projectsScoped, form.organizationId]);

  /**
   * Options du filtre : uniquement les projets du **contexte organisation actif** (comme le reste de l’écran),
   * plus les projets référencés par des workflows **de cette même organisation** si absents de la liste API.
   */
  const filterProjectOptions = useMemo(() => {
    const activeOrgId =
      user.organization?.id != null && user.organization.id !== ''
        ? String(user.organization.id)
        : null;
    const byId = new Map();
    for (const p of projectsForActiveOrg) {
      byId.set(String(p.id), {
        id: p.id,
        name: p.name,
        organizationName: p.organizationName ?? null,
      });
    }
    for (const w of items) {
      if (activeOrgId != null) {
        const wOrg = webhookRowOrganizationId(w);
        if (wOrg == null || wOrg !== activeOrgId) continue;
      }
      const pid = webhookRowProjectId(w);
      if (!pid || byId.has(pid)) continue;
      const orgName = w.organization?.name ?? null;
      const wid = w.project?.id != null ? w.project.id : Number.parseInt(String(pid), 10);
      byId.set(pid, {
        id: Number.isFinite(Number(wid)) ? Number(wid) : pid,
        name: w.project?.name ?? `Projet #${pid}`,
        organizationName: orgName,
      });
    }
    const list = Array.from(byId.values());
    const nameCount = new Map();
    for (const p of list) {
      const k = String(p.name ?? '')
        .trim()
        .toLowerCase();
      const key = k || '—';
      nameCount.set(key, (nameCount.get(key) ?? 0) + 1);
    }
    const withLabels = list.map((p) => {
      const k = String(p.name ?? '')
        .trim()
        .toLowerCase();
      const dup = (nameCount.get(k || '—') ?? 0) > 1;
      const org = p.organizationName;
      const tagLabel = dup && org ? `${p.name} · ${org}` : p.name;
      return { ...p, tagLabel };
    });
    return withLabels.sort((a, b) =>
      String(a.tagLabel).localeCompare(String(b.tagLabel), 'fr', { sensitivity: 'base' }),
    );
  }, [projectsForActiveOrg, items, user.organization?.id]);

  /**
   * Admin : les pastilles « projet » sont limitées à l’organisation active (sélecteur d’en-tête) ;
   * la liste doit suivre le même périmètre, sinon un filtre « Général » applique l’ID projet local
   * alors que les cartes affichées peuvent être d’autres organisations (API liste globale).
   */
  const activeOrgIdForWorkflowList = useMemo(() => {
    if (!isAdmin) return null;
    const oid = user.organization?.id;
    if (oid == null || oid === '') return null;
    return String(oid);
  }, [isAdmin, user.organization?.id]);

  const listSearchNorm = listSearchQuery.trim().toLowerCase();

  useEffect(() => {
    setProjectFilter('all');
  }, [user.organization?.id]);

  const visibleWebhookItems = useMemo(() => {
    let rows = items.filter((row) => {
      if (activeOrgIdForWorkflowList != null) {
        const wOrg = webhookRowOrganizationId(row);
        if (wOrg !== activeOrgIdForWorkflowList) return false;
      }
      if (projectFilter === 'all') return true;
      const pid = webhookRowProjectId(row);
      return pid !== null && pid === String(projectFilter);
    });
    if (listSearchNorm) {
      rows = rows.filter((row) => {
        const name = (row.name ?? '').toLowerCase();
        const desc = (row.description ?? '').toLowerCase();
        return name.includes(listSearchNorm) || desc.includes(listSearchNorm);
      });
    }
    return [...rows].sort((a, b) =>
      String(a.name).localeCompare(String(b.name), 'fr', { sensitivity: 'base' }),
    );
  }, [items, projectFilter, listSearchNorm, activeOrgIdForWorkflowList]);

  const loadLogsForId = useCallback(async (webhookId) => {
    const row = items.find((w) => w.id === webhookId);
    setLogsWebhookName(row?.name ?? `Webhook #${webhookId}`);
    setLogsWebhookId(webhookId);
    setLogs(null);
    setLogDetail(null);
    setSelectedLogId(null);
    setLogDetailError('');
    setView('logs');
    const res = await fetch(`/api/form-webhooks/${webhookId}/logs?limit=50`, { credentials: 'include' });
    const data = await parseJson(res);
    if (res.ok) setLogs(data);
    else setLogs({ error: data?.error });
  }, [items]);

  const fillEditForm = useCallback(
    (row) => {
      const raw = Array.isArray(row.actions) && row.actions.length > 0 ? row.actions : [];
      const sortedRaw = raw.length > 0 ? [...raw].sort((a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0)) : [];
      const actions =
        sortedRaw.length > 0
          ? sortedRaw.map((a) => ({
              _uiKey: a.id != null ? `act-${a.id}` : newActionRowKey(),
              actionType: a.actionType || ACTION_MAILJET,
              mailjetId: a.mailjetId != null ? String(a.mailjetId) : '',
              mailjetTemplateId: a.mailjetTemplateId != null ? String(a.mailjetTemplateId) : '',
              templateLanguage: !!a.templateLanguage,
              serviceConnectionId: a.serviceConnectionId != null ? String(a.serviceConnectionId) : '',
              payloadTemplate: a.payloadTemplate ?? '',
              smsToPostKey: a.smsToPostKey ?? '',
              smsToDefault: a.smsToDefault ?? '',
              variableMapping: JSON.stringify(a.variableMapping ?? {}, null, 2),
              recipientEmailPostKey: a.recipientEmailPostKey ?? '',
              recipientNamePostKey: a.recipientNamePostKey ?? '',
              defaultRecipientEmail: a.defaultRecipientEmail ?? '',
              comment: a.comment ?? '',
              active: a.active !== false,
            }))
          : [defaultActionRow(mailjetConnections)];
      setForm({
        name: row.name,
        description: row.description ?? '',
        ...(isAdmin ? { organizationId: row.organizationId != null ? String(row.organizationId) : '' } : {}),
        active: !!row.active,
        lifecycle: row.lifecycle === 'draft' ? 'draft' : 'production',
        notificationEmailSource: row.notificationEmailSource === 'custom' ? 'custom' : 'creator',
        notificationCustomEmail: row.notificationCustomEmail ?? '',
        notifyOnError: row.notifyOnError !== false,
        notificationCreatorHint: row.createdByEmail ?? user?.email ?? '',
        projectId: row.projectId != null ? String(row.projectId) : '',
        webhookVersion: row.version != null ? Number(row.version) : 1,
        actions,
      });
      const rid = row.id;
      let normalizedEditingId = rid;
      if (rid != null && rid !== 'new') {
        if (typeof rid === 'number' && Number.isFinite(rid)) {
          normalizedEditingId = rid;
        } else if (typeof rid === 'string' && /^\d+$/.test(rid.trim())) {
          normalizedEditingId = Number(rid);
        }
      }
      setEditingId(normalizedEditingId);
      setView('editor');
      setError('');
    },
    [isAdmin, user?.email, mailjetConnections],
  );

  const routeKind = route?.kind ?? 'list';
  const routeId = route && route.kind !== 'list' ? route.id : undefined;

  useEffect(() => {
    if (!onWebhooksNavigate || !route) return;
    let cancelled = false;

    if (routeKind === 'list') {
      if (editingId === 'new') return;
      if (view === 'list' && editingId === null && logsWebhookId === null && detailWebhook === null) return;
      setView('list');
      setEditingId(null);
      setForm(emptyForm);
      setLogsWebhookId(null);
      setLogsWebhookName('');
      setLogs(null);
      setLogDetail(null);
      setSelectedLogId(null);
      setLogDetailError('');
      setDetailWebhook(null);
      return undefined;
    }

    if (routeKind === 'detail' && routeId != null) {
      if (detailWebhook?.id === routeId && view === 'detail') return undefined;
      const row = items.find((w) => w.id === routeId);
      if (row) {
        setDetailWebhook(row);
        setView('detail');
        setEditingId(null);
        setError('');
        return undefined;
      }
      if (loading) return undefined;
      void (async () => {
        setError('');
        const res = await fetch(`/api/form-webhooks/${routeId}`, { credentials: 'include' });
        const data = await parseJson(res);
        if (cancelled) return;
        if (res.ok) {
          setDetailWebhook(data);
          setView('detail');
          setEditingId(null);
          setError('');
        } else {
          setError(data?.error ?? 'Webhook introuvable ou accès refusé.');
          onWebhooksNavigate({ kind: 'list' });
        }
      })();
      return () => {
        cancelled = true;
      };
    }

    if (routeKind === 'edit' && routeId != null) {
      const rid = Number(routeId);
      if (Number.isNaN(rid)) return undefined;
      /** Même workflow déjà ouvert dans l’éditeur : ne pas recharger depuis la liste (risque d’`actions` vides / désynchro). */
      const alreadyInEditor =
        view === 'editor' &&
        editingId !== 'new' &&
        editingId != null &&
        Number(editingId) === rid;
      if (alreadyInEditor) return undefined;
      if (loading) return undefined;
      void (async () => {
        setError('');
        const res = await fetch(`/api/form-webhooks/${rid}`, { credentials: 'include' });
        const data = await parseJson(res);
        if (cancelled) return;
        if (savingRef.current) return;
        if (res.ok) fillEditForm(data);
        else {
          setError(data?.error ?? 'Webhook introuvable ou accès refusé.');
          onWebhooksNavigate({ kind: 'list' });
        }
      })();
      return () => {
        cancelled = true;
      };
    }

    if (routeKind === 'logs' && routeId != null) {
      if (logsWebhookId === routeId && view === 'logs' && logs != null) return undefined;
      void loadLogsForId(routeId);
    }
    return undefined;
  }, [
    routeKind,
    routeId,
    items,
    loading,
    onWebhooksNavigate,
    route,
    fillEditForm,
    loadLogsForId,
    editingId,
    view,
    logsWebhookId,
    logs,
    detailWebhook,
  ]);

  const goToList = () => {
    onWebhooksNavigate?.({ kind: 'list' });
    setView('list');
    setEditingId(null);
    setForm(emptyForm);
    setLogsWebhookId(null);
    setLogsWebhookName('');
    setLogs(null);
    setLogDetail(null);
    setSelectedLogId(null);
    setLogDetailError('');
    setDetailWebhook(null);
    setSaveSuccessMessage('');
  };

  const openDetail = (row) => {
    setDetailWebhook(row);
    setView('detail');
    setError('');
    onWebhooksNavigate?.({ kind: 'detail', id: row.id });
  };

  const goToDetailById = (webhookId) => {
    onWebhooksNavigate?.({ kind: 'detail', id: webhookId });
  };

  const openLogs = (webhookId) => {
    onWebhooksNavigate?.({ kind: 'logs', id: webhookId });
  };

  const openLogDetail = async (logId) => {
    setSelectedLogId(logId);
    setLogDetailLoading(true);
    setLogDetailError('');
    setLogDetail(null);
    try {
      const res = await fetch(`/api/form-webhooks/logs/${logId}`, { credentials: 'include' });
      const data = await parseJson(res);
      if (res.ok) setLogDetail(data);
      else setLogDetailError(data?.error ?? 'Erreur');
    } catch {
      setLogDetailError('Erreur réseau');
    } finally {
      setLogDetailLoading(false);
    }
  };

  const startCreate = () => {
    if (missingProjectsBlock) return;
    onWebhooksNavigate?.({ kind: 'list' });
    setForm({
      ...emptyForm,
      ...(isAdmin && orgs[0] ? { organizationId: String(orgs[0].id) } : {}),
      notificationCreatorHint: user?.email ?? '',
      /** Brouillon : pas de ligne d’action fantôme — l’utilisateur en ajoute depuis l’onglet Actions. */
      actions: [],
    });
    setEditingId('new');
    setView('editor');
    setError('');
    setSaveSuccessMessage('');
  };

  const startEdit = (row) => {
    fillEditForm(row);
    onWebhooksNavigate?.({ kind: 'edit', id: row.id });
  };

  const cancelEdit = () => {
    setSaveSuccessMessage('');
    if (editingId != null && editingId !== 'new') {
      onWebhooksNavigate?.({ kind: 'detail', id: editingId });
      return;
    }
    goToList();
  };

  /**
   * Indique si chaque ligne du formulaire d’actions est suffisamment renseignée pour envoi API.
   * Utilisé à la création pour éviter d’appeler la construction payload (et tout message d’erreur) tant que l’utilisateur n’a pas finalisé les actions.
   */
  const areFormActionsReadyForApi = () => {
    if (!form.actions?.length) return false;
    for (let idx = 0; idx < form.actions.length; idx++) {
      const a = form.actions[idx];
      try {
        JSON.parse(a.variableMapping || '{}');
      } catch {
        return false;
      }
      const at = a.actionType || ACTION_MAILJET;
      if (at === ACTION_MAILJET) {
        const sid = a.serviceConnectionId ? Number(a.serviceConnectionId) : 0;
        const legacyMj = !sid && a.mailjetId ? Number(a.mailjetId) : 0;
        if (sid < 1 && legacyMj < 1) return false;
        const tplId = Number(a.mailjetTemplateId);
        if (!tplId || tplId < 1) return false;
      } else {
        const sidOther = a.serviceConnectionId ? Number(a.serviceConnectionId) : 0;
        if (sidOther < 1 || Number.isNaN(sidOther)) return false;
      }
    }
    return true;
  };

  /** @returns {object[]} */
  const buildActionsPayloadForApi = () => {
    if (!form.actions?.length) {
      throw new SyntaxError('Au moins une action est requise');
    }
    return form.actions.map((a, idx) => {
      let mapping = {};
      try {
        mapping = JSON.parse(a.variableMapping || '{}');
      } catch {
        throw new SyntaxError(`Action #${idx + 1} : variableMapping JSON invalide`);
      }
      const at = a.actionType || ACTION_MAILJET;
      if (at === ACTION_MAILJET) {
        const sid = a.serviceConnectionId ? Number(a.serviceConnectionId) : 0;
        const legacyMj = !sid && a.mailjetId ? Number(a.mailjetId) : 0;
        if (sid < 1 && legacyMj < 1) {
          throw new SyntaxError(`Action #${idx + 1} : sélectionnez un connecteur Mailjet (Intégrations).`);
        }
        const tplId = Number(a.mailjetTemplateId);
        if (!tplId || tplId < 1) {
          throw new SyntaxError(`Action #${idx + 1} : ID template Mailjet requis.`);
        }
        const commentMj = String(a.comment ?? '').trim();
        const row = {
          actionType: ACTION_MAILJET,
          mailjetTemplateId: tplId,
          templateLanguage: !!a.templateLanguage,
          variableMapping: mapping,
          recipientEmailPostKey: a.recipientEmailPostKey || null,
          recipientNamePostKey: a.recipientNamePostKey || null,
          defaultRecipientEmail: a.defaultRecipientEmail || null,
          comment: commentMj || null,
          active: !!a.active,
          sortOrder: idx,
        };
        if (sid >= 1) row.serviceConnectionId = sid;
        if (legacyMj >= 1) row.mailjetId = legacyMj;
        return row;
      }
      const sidOther = a.serviceConnectionId ? Number(a.serviceConnectionId) : 0;
      if (sidOther < 1 || Number.isNaN(sidOther)) {
        throw new SyntaxError(`Action #${idx + 1} : sélectionnez un connecteur enregistré.`);
      }
      const commentOth = String(a.comment ?? '').trim();
      return {
        actionType: at,
        serviceConnectionId: sidOther,
        variableMapping: mapping,
        payloadTemplate: a.payloadTemplate?.trim() ? String(a.payloadTemplate) : null,
        smsToPostKey: a.smsToPostKey?.trim() ? String(a.smsToPostKey) : null,
        smsToDefault: a.smsToDefault?.trim() ? String(a.smsToDefault) : null,
        comment: commentOth || null,
        active: !!a.active,
        sortOrder: idx,
      };
    });
  };

  const bodyFromForm = () => {
    const actions = buildActionsPayloadForApi();
    const body = {
      name: form.name,
      description: form.description || null,
      active: form.active,
      notificationEmailSource: form.notificationEmailSource === 'custom' ? 'custom' : 'creator',
      notificationCustomEmail:
        form.notificationEmailSource === 'custom' && form.notificationCustomEmail
          ? String(form.notificationCustomEmail).trim()
          : null,
      notifyOnError: !!form.notifyOnError,
      lifecycle: form.lifecycle === 'production' ? 'production' : 'draft',
      actions,
    };
    if (isAdmin) body.organizationId = Number(form.organizationId);
    const projectId = form.projectId ? Number(form.projectId) : null;
    if (!projectId) {
      throw new SyntaxError('Choisissez un projet pour ce workflow (écran Projets si besoin).');
    }
    body.projectId = projectId;
    return body;
  };

  /** Création : enregistrement par étapes — brouillon possible sans actions ; le workflow reste actif par défaut (interrupteur formulaire). */
  const bodyFromFormCreateDraftAware = () => {
    const name = String(form.name ?? '').trim();
    if (!name) {
      throw new SyntaxError('Le nom du workflow est obligatoire.');
    }
    if (form.notificationEmailSource === 'custom') {
      const ce = String(form.notificationCustomEmail ?? '').trim();
      if (!ce || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ce)) {
        throw new SyntaxError(
          'Une adresse e-mail valide est requise lorsque la notification est envoyée à une adresse personnalisée.',
        );
      }
    }
    let actionsPayload = [];
    let incompleteActions = true;
    if (areFormActionsReadyForApi()) {
      try {
        actionsPayload = buildActionsPayloadForApi();
        incompleteActions = false;
      } catch {
        incompleteActions = true;
        actionsPayload = [];
      }
    }
    const body = {
      name,
      description: form.description || null,
      active: !!form.active,
      notificationEmailSource: form.notificationEmailSource === 'custom' ? 'custom' : 'creator',
      notificationCustomEmail:
        form.notificationEmailSource === 'custom' && form.notificationCustomEmail
          ? String(form.notificationCustomEmail).trim()
          : null,
      notifyOnError: !!form.notifyOnError,
      lifecycle: form.lifecycle === 'production' ? 'production' : 'draft',
      actions: actionsPayload,
    };
    if (isAdmin) body.organizationId = Number(form.organizationId);
    const projectId = form.projectId ? Number(form.projectId) : null;
    if (!projectId) {
      throw new SyntaxError('Choisissez un projet pour ce workflow (écran Projets si besoin).');
    }
    body.projectId = projectId;
    return body;
  };

  const updateAction = (index, patch) => {
    setForm((f) => {
      const next = [...(f.actions || [])];
      next[index] = { ...next[index], ...patch };
      return { ...f, actions: next };
    });
  };

  const addActionRow = () => {
    setForm((f) => {
      const a = defaultActionRow(mailjetConnections);
      return { ...f, actions: [...(f.actions || []), a] };
    });
  };

  const removeActionRow = (index) => {
    setForm((f) => {
      const next = [...(f.actions || [])];
      if (next.length === 0) return f;
      if (editingId !== 'new' && next.length <= 1) return f;
      next.splice(index, 1);
      return { ...f, actions: next };
    });
  };

  const reorderActionRow = (fromIndex, toIndex) => {
    if (fromIndex === toIndex) return;
    setForm((f) => {
      const next = [...(f.actions || [])];
      if (fromIndex < 0 || fromIndex >= next.length || toIndex < 0 || toIndex >= next.length) return f;
      const [item] = next.splice(fromIndex, 1);
      next.splice(toIndex, 0, item);
      return { ...f, actions: next };
    });
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    if (projectsForEditor.length === 0) {
      setError('Aucun projet disponible : créez un projet dans l’écran Projets avant d’ajouter un workflow.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const body = bodyFromFormCreateDraftAware();
      const res = await fetch('/api/form-webhooks', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? JSON.stringify(data?.fields ?? {}));
        return;
      }
      await refresh();
      const nActions = Array.isArray(data.actions) ? data.actions.length : 0;
      if (nActions === 0 && data.id != null) {
        fillEditForm(data);
        onWebhooksNavigate?.({ kind: 'edit', id: data.id });
      } else {
        cancelEdit();
      }
    } catch (err) {
      setError(err?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const submitUpdate = async (e) => {
    e.preventDefault();
    if (editingId === 'new' || editingId == null) return;
    savingRef.current = true;
    setSaving(true);
    setError('');
    setSaveSuccessMessage('');
    try {
      /** Copie figée au clic : les awaits peuvent laisser un `setForm` intermédiaire avec des actions vides. */
      const pinnedActions = (form.actions ?? []).map((a) => ({ ...a }));
      const body = bodyFromForm();
      const res = await fetch(`/api/form-webhooks/${editingId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? JSON.stringify(data?.fields ?? {}));
        return;
      }
      await refresh();
      let rowMeta = data;
      if (data && typeof data === 'object' && data.id != null) {
        const resFresh = await fetch(`/api/form-webhooks/${data.id}`, { credentials: 'include' });
        const fresh = await parseJson(resFresh);
        if (resFresh.ok && fresh && typeof fresh === 'object' && fresh.id != null) {
          rowMeta = fresh;
        }
      }
      /**
       * Après enregistrement : ne pas réinjecter les actions depuis l’API (réponse parfois incomplète côté ORM /
       * cache). On met à jour nom, version, notifs, etc. et on garde les lignes d’actions déjà dans le formulaire.
       */
      if (rowMeta && typeof rowMeta === 'object' && rowMeta.id != null) {
        const wid = Number(rowMeta.id);
        setForm((f) => ({
          ...f,
          name: rowMeta.name ?? f.name,
          description: rowMeta.description ?? f.description,
          ...(isAdmin
            ? { organizationId: rowMeta.organizationId != null ? String(rowMeta.organizationId) : f.organizationId }
            : {}),
          active: rowMeta.active !== undefined ? !!rowMeta.active : f.active,
          lifecycle:
            rowMeta.lifecycle === 'draft'
              ? 'draft'
              : rowMeta.lifecycle === 'production'
                ? 'production'
                : f.lifecycle,
          notificationEmailSource: rowMeta.notificationEmailSource === 'custom' ? 'custom' : 'creator',
          notificationCustomEmail: rowMeta.notificationCustomEmail ?? f.notificationCustomEmail,
          notifyOnError: rowMeta.notifyOnError !== false,
          notificationCreatorHint: rowMeta.createdByEmail ?? f.notificationCreatorHint ?? user?.email ?? '',
          projectId: rowMeta.projectId != null ? String(rowMeta.projectId) : f.projectId,
          webhookVersion: rowMeta.version != null ? Number(rowMeta.version) : f.webhookVersion,
          actions:
            pinnedActions.length > 0 ? pinnedActions : Array.isArray(f.actions) && f.actions.length > 0 ? f.actions : [],
        }));
        setEditingId(Number.isFinite(wid) && wid > 0 ? wid : rowMeta.id);
        onWebhooksNavigate?.({ kind: 'edit', id: Number.isFinite(wid) && wid > 0 ? wid : rowMeta.id });
        setSaveSuccessMessage('Modifications enregistrées.');
      }
    } catch (err) {
      setError(err?.message ?? 'Erreur');
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  };

  const remove = async (id) => {
    if (!window.confirm('Supprimer ce webhook ?')) return;
    setError('');
    const res = await fetch(`/api/form-webhooks/${id}`, { method: 'DELETE', credentials: 'include' });
    if (!res.ok && res.status !== 204) {
      const data = await parseJson(res);
      setError(data?.error ?? 'Suppression impossible');
      return;
    }
    if (logsWebhookId === id || detailWebhook?.id === id) {
      goToList();
    }
    await refresh();
  };

  const duplicateWebhook = async (sourceRow) => {
    if (user.subscription != null && !user.subscription.canCreateWebhook) return;
    setError('');
    setSaving(true);
    try {
      const res = await fetch(`/api/form-webhooks/${sourceRow.id}/duplicate`, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Duplication impossible');
        return;
      }
      await refresh();
      if (data && typeof data === 'object' && data.id != null) {
        setDetailWebhook(data);
        setView('detail');
        onWebhooksNavigate?.({ kind: 'detail', id: data.id });
        setEditingId(null);
      }
    } catch {
      setError('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const copyUrl = (url) => {
    void navigator.clipboard.writeText(url);
  };

  if (!user.organization && !isAdmin) {
    return (
      <div className="users-shell org-section fw-app fw-workflows-list-page">
        <header className="users-hero users-hero--minimal">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-diagram-project" aria-hidden />
            <span>Workflows</span>
          </h1>
        </header>
        <div className="content-card">
          <p className="muted">Rattachez-vous à une organisation pour configurer des webhooks.</p>
        </div>
      </div>
    );
  }

  if (
    loading &&
    routeKind === 'detail' &&
    routeId != null &&
    !detailWebhook
  ) {
    return (
      <section className="org-section fw-app">
        <div className="content-card">
          <div className="admin-loading-inner" style={{ padding: '3rem' }}>
            <div className="admin-spinner" />
            <p className="muted">Chargement de la fiche workflow…</p>
          </div>
        </div>
      </section>
    );
  }

  if (loading && view === 'list' && routeKind === 'list') {
    return (
      <div className="users-shell org-section fw-app fw-workflows-list-page">
        <header className="users-hero users-hero--minimal">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-diagram-project" aria-hidden />
            <span>Workflows</span>
          </h1>
        </header>
        <div className="content-card">
          <div className="admin-loading-inner" style={{ padding: '3rem' }}>
            <div className="admin-spinner" />
            <p className="muted">Chargement des workflows…</p>
          </div>
        </div>
      </div>
    );
  }

  const isCreate = editingId === 'new';

  const cannotSubmitNewWithoutProject = isCreate && projectsForEditor.length === 0;

  const editorForm = (
    <form
      className="fw-editor-stack mailjet-form"
      noValidate
      onSubmit={(e) => (isCreate ? void submitCreate(e) : void submitUpdate(e))}
      style={{ border: 'none', background: 'transparent', padding: 0, margin: 0, maxWidth: 'none' }}
    >
      {error ? <p className="error" style={{ marginBottom: '1rem' }}>{error}</p> : null}
      {cannotSubmitNewWithoutProject ? (
        <div className="fw-projects-required-banner" role="alert" style={{ marginBottom: '1rem' }}>
          <p>
            <strong>Aucun projet</strong> pour l’organisation sélectionnée. Créez un projet depuis l’écran{' '}
            <strong>Projets</strong>, puis revenez créer un workflow.
          </p>
          <div className="fw-projects-required-actions">
            <button type="button" className="btn small" onClick={() => onAppNavigate?.('webhookProjects')}>
              Ouvrir Projets
            </button>
            <button type="button" className="btn secondary small" onClick={cancelEdit}>
              Retour à la liste
            </button>
          </div>
        </div>
      ) : null}

      <div
        className="fw-editor-panel-single"
        role="tabpanel"
        id={`fw-editor-panel-${editorNavSection}`}
        aria-labelledby={`fw-editor-tab-${editorNavSection}`}
      >
        {editorNavSection === 'general' ? (
          <div id="fw-editor-section-general" className="fw-editor-section fw-block fw-block-general">
            <header className="fw-block-header">
              <span className="fw-block-step">Général</span>
              <h2>Identification et classement</h2>
              <p className="fw-block-intro">
                Nom, description et projet. L’activation de l’URL (actif / inactif) se règle en haut à droite ; le passage en
                production (exécution des actions) se choisit ci-dessous.
              </p>
            </header>
            {isAdmin ? (
              <label className="field">
                <span>Organisation</span>
                <select
                  value={form.organizationId ?? ''}
                  onChange={(e) => setForm((f) => ({ ...f, organizationId: e.target.value }))}
                  required
                >
                  {orgs.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name} (#{o.id})
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="field">
              <span>Nom du workflow</span>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
            </label>
            <label className="field">
              <span>Description</span>
              <input
                value={form.description}
                onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
              />
            </label>
            <label className="field">
              <span>Projet</span>
              <select
                value={form.projectId ?? ''}
                onChange={(e) => setForm((f) => ({ ...f, projectId: e.target.value }))}
                required
                disabled={projectsForEditor.length === 0}
              >
                {projectsForEditor.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                    {p.isDefault ? ' (par défaut)' : ''}
                    {isAdmin && p.organizationName ? ` — ${p.organizationName}` : ''}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>État de mise en ligne</span>
              <select
                value={form.lifecycle === 'production' ? 'production' : 'draft'}
                onChange={(e) => setForm((f) => ({ ...f, lifecycle: e.target.value }))}
              >
                <option value="draft">Brouillon — réceptions tracées, aucune action exécutée</option>
                <option value="production">En production — exécution des actions du flux</option>
              </select>
            </label>
            <p className="muted small" style={{ marginTop: '-0.25rem' }}>
              Un workflow appartient toujours à un projet. Créez ou renommez des dossiers depuis <strong>Projets</strong>{' '}
              dans le menu.
            </p>
          </div>
        ) : null}

        {editorNavSection === 'notification' ? (
          <div id="fw-editor-section-notification" className="fw-editor-section fw-block fw-block-notify">
            <header className="fw-block-header">
              <span className="fw-block-step">Notification</span>
              <h2>Récapitulatifs Webhooky</h2>
              <p className="fw-block-intro">
                E-mails de synthèse envoyés par la plateforme (expéditeur{' '}
                <code className="mono" style={{ fontSize: '0.85em' }}>
                  notification@webhooky.builders
                </code>
                ). Site :{' '}
                <a href="https://webhooky.builders" target="_blank" rel="noopener noreferrer">
                  webhooky.builders
                </a>
                .
              </p>
            </header>
            <div className="fw-notifications" style={{ marginTop: '0.35rem' }}>
              <div className="field" role="group" aria-label="Destinataire des notifications">
                <span>Envoyer les notifications à</span>
                <label
                  className="field"
                  style={{ flexDirection: 'row', alignItems: 'flex-start', gap: '0.55rem', marginTop: '0.35rem' }}
                >
                  <input
                    type="radio"
                    name="fw-notif-email-src"
                    checked={form.notificationEmailSource !== 'custom'}
                    onChange={() => setForm((f) => ({ ...f, notificationEmailSource: 'creator' }))}
                  />
                  <span>
                    L’utilisateur qui a créé ce workflow
                    {form.notificationCreatorHint ? (
                      <>
                        {' '}
                        <span className="mono muted small">({form.notificationCreatorHint})</span>
                      </>
                    ) : null}
                  </span>
                </label>
                <label
                  className="field"
                  style={{ flexDirection: 'row', alignItems: 'flex-start', gap: '0.55rem', marginTop: '0.35rem' }}
                >
                  <input
                    type="radio"
                    name="fw-notif-email-src"
                    checked={form.notificationEmailSource === 'custom'}
                    onChange={() => setForm((f) => ({ ...f, notificationEmailSource: 'custom' }))}
                  />
                  <span>Adresse e-mail personnalisée</span>
                </label>
                {form.notificationEmailSource === 'custom' ? (
                  <label className="field" style={{ marginTop: '0.5rem', marginLeft: '1.6rem' }}>
                    <span>E-mail de notification</span>
                    <input
                      type="email"
                      value={form.notificationCustomEmail ?? ''}
                      onChange={(e) => setForm((f) => ({ ...f, notificationCustomEmail: e.target.value }))}
                      placeholder="vous@exemple.fr"
                      required
                    />
                  </label>
                ) : null}
              </div>
              <label
                className="field"
                style={{ flexDirection: 'row', alignItems: 'center', gap: '0.5rem', marginTop: '1rem' }}
              >
                <input
                  type="checkbox"
                  checked={form.notifyOnError !== false}
                  onChange={(e) => setForm((f) => ({ ...f, notifyOnError: e.target.checked }))}
                />
                <span>Envoyer un récap en cas d’erreur (recommandé)</span>
              </label>
            </div>
          </div>
        ) : null}

        {editorNavSection === 'trigger' ? (
          <div id="fw-editor-section-trigger" className="fw-editor-section fw-block fw-block-trigger">
            <header className="fw-block-header">
              <span className="fw-block-step">Déclencheur</span>
              <h2>Ce qui démarre le workflow</h2>
              <p className="fw-block-intro">
                Le déclencheur reçoit les données en premier. D’autres types (planification, fichier, etc.) pourront
                être proposés plus tard.
              </p>
            </header>
            <div className="fw-trigger-type-row">
              <span className="fw-type-badge">Webhook · POST entrant</span>
              <span className="muted small">URL unique générée à l’enregistrement, corps formulaire ou JSON.</span>
            </div>
          </div>
        ) : null}

        {editorNavSection === 'actions' ? (
          <div id="fw-editor-section-actions" className="fw-editor-section fw-block fw-block-actions">
            <header className="fw-block-header">
              <span className="fw-block-step">Actions</span>
              <h2>Ce qui s’exécute ensuite</h2>
              <p className="fw-block-intro">
                Les actions s’enchaînent après chaque réception : Mailjet (e-mail template) ou connecteurs configurés dans{' '}
                <strong>Intégrations</strong> (Slack, SMS, Telegram, HTTP…). Chaque action compte comme un événement
                quota.
              </p>
            </header>
            <div className="fw-actions-type-row">
              <span className="fw-type-badge fw-type-badge-neutral">Actions multiples</span>
              <span className="muted small">
                Réponse HTTP positive seulement si toutes les actions actives réussissent. Ordre d’exécution = ordre des
                blocs ci-dessous (glisser-déposer via la poignée à gauche de chaque carte).
              </span>
            </div>
            {(form.actions || []).length === 0 ? (
              <p className="muted small" style={{ margin: '0 0 0.85rem' }}>
                Aucune action pour l’instant : vous pouvez enregistrer un brouillon (workflow inactif) et en ajouter
                ensuite, ou cliquer sur <strong>+ Ajouter une action</strong> ci-dessous.
              </p>
            ) : null}
            <div className="fw-actions-editor-list">
              {(form.actions || []).map((a, i) => (
                <div
                  key={a._uiKey ?? i}
                  className="fw-action-drop-wrap"
                  onDragOver={(e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                  }}
                  onDrop={(e) => {
                    e.preventDefault();
                    const raw =
                      e.dataTransfer.getData(FW_ACTION_DRAG_MIME) || e.dataTransfer.getData('text/plain');
                    const from = Number.parseInt(String(raw).trim(), 10);
                    if (!Number.isNaN(from) && from >= 0 && from < (form.actions || []).length) {
                      reorderActionRow(from, i);
                    }
                    setActionDraggingKey(null);
                  }}
                >
                  <ActionFields
                    action={a}
                    index={i}
                    totalActions={(form.actions || []).length}
                    mailjetConnections={mailjetConnections}
                    serviceConnections={serviceConnectionsScoped}
                    vendorUrlByType={integrationVendorUrlByType}
                    updateAction={updateAction}
                    onRemove={removeActionRow}
                    canRemove={(form.actions || []).length > 1 || isCreate}
                    dragIndex={i}
                    onDragHandleStart={() => setActionDraggingKey(a._uiKey ?? `i${i}`)}
                    onDragHandleEnd={() => setActionDraggingKey(null)}
                    isDragging={(a._uiKey ?? `i${i}`) === actionDraggingKey}
                  />
                </div>
              ))}
            </div>
            <button type="button" className="fw-btn-ghost" style={{ marginBottom: '0.25rem' }} onClick={addActionRow}>
              + Ajouter une action
            </button>
          </div>
        ) : null}

        {editorNavSection === 'history' ? (
          <div id="fw-editor-section-history" className="fw-editor-section fw-block">
            <header className="fw-block-header">
              <span className="fw-block-step">Historique</span>
              <h2>Modifications tracées</h2>
              <p className="fw-block-intro">
                Chaque modification effective augmente la <strong>version</strong> du workflow. Les créations, mises à jour
                et suppressions sont enregistrées avec auteur et horodatage (adresse IP côté serveur).
              </p>
            </header>
            {webhookAuditLoading ? <p className="muted">Chargement…</p> : null}
            {webhookAuditError ? <p className="error">{webhookAuditError}</p> : null}
            {!webhookAuditLoading && webhookAudit?.items?.length === 0 ? (
              <p className="muted">Aucune entrée d’audit pour ce workflow.</p>
            ) : null}
            {webhookAudit?.items?.length > 0 ? (
              <ul className="fw-audit-list" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                {webhookAudit.items.map((entry) => (
                  <li
                    key={entry.id}
                    className="fw-audit-item"
                    style={{
                      borderBottom: '1px solid var(--input-border)',
                      padding: '0.75rem 0',
                    }}
                  >
                    <WorkflowAuditEntry entry={entry} />
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}
      </div>

      <div className="fw-editor-actions-bar row" style={{ marginTop: 0 }}>
        <button type="submit" className="btn" disabled={saving || cannotSubmitNewWithoutProject}>
          {isCreate ? 'Créer le workflow' : 'Enregistrer les modifications'}
        </button>
        <button type="button" className="btn secondary" onClick={cancelEdit} disabled={saving}>
          Annuler
        </button>
      </div>
    </form>
  );

  return (
    <section className="org-section fw-app">
      {view === 'list' && (
        <div className="users-shell fw-workflows-list-page">
          <header className="users-hero users-hero--minimal">
            <h1 className="users-hero-title">
              <i className="fa-solid fa-diagram-project" aria-hidden />
              <span>Workflows</span>
            </h1>
            <div className="users-hero-actions wp-projects-hero-actions">
              <button
                type="button"
                className="fw-btn-primary wp-proj-hero-btn"
                onClick={startCreate}
                disabled={
                  missingProjectsBlock || (user.subscription != null && !user.subscription.canCreateWebhook)
                }
                title={
                  missingProjectsBlock
                    ? 'Créez au moins un projet avant d’ajouter un workflow'
                    : undefined
                }
              >
                <i className="fa-solid fa-plus" aria-hidden />
                <span>Nouveau workflow</span>
              </button>
            </div>
          </header>

          <div className="content-card">
          {missingProjectsBlock ? (
            <div className="fw-projects-required-banner" role="status">
              <p>
                <strong>Aucun projet dans cette organisation.</strong> Un workflow doit être rattaché à un projet.
                Créez d’abord un projet (par exemple depuis l’écran <strong>Projets</strong>), puis revenez ici pour
                ajouter un webhook.
              </p>
              <div className="fw-projects-required-actions">
                <button type="button" className="btn small" onClick={() => onAppNavigate?.('webhookProjects')}>
                  Ouvrir Projets
                </button>
              </div>
            </div>
          ) : null}

          {error ? <p className="error">{error}</p> : null}

          {items.length === 0 ? (
            <div className="fw-empty">
              <h3>Aucun workflow pour l’instant</h3>
              {missingProjectsBlock ? (
                <>
                  <p>
                    Votre organisation n’a pas encore de projet. Les workflows doivent être classés dans un projet
                    (le dossier <strong>Général</strong> est normalement créé automatiquement à la création de
                    l’organisation — en cas de doute, contactez un administrateur).
                  </p>
                  <button
                    type="button"
                    className="fw-btn-primary"
                    style={{ marginTop: '1rem' }}
                    onClick={() => onAppNavigate?.('webhookProjects')}
                  >
                    Gérer les projets
                  </button>
                </>
              ) : (
                <>
                  <p>Créez votre premier webhook : une URL POST, puis une ou plusieurs actions Mailjet.</p>
                  <button
                    type="button"
                    className="fw-btn-primary"
                    style={{ marginTop: '1rem' }}
                    onClick={startCreate}
                    disabled={user.subscription != null && !user.subscription.canCreateWebhook}
                  >
                    Créer un workflow
                  </button>
                </>
              )}
            </div>
          ) : (
            <>
              <div className="fw-workflow-filters-row">
                <div className="fw-workflow-search-wrap">
                  <label htmlFor="fw-workflow-search" className="fw-sr-only">
                    Rechercher un workflow
                  </label>
                  <input
                    id="fw-workflow-search"
                    type="search"
                    className="fw-workflow-search-input"
                    placeholder="Rechercher par nom ou description…"
                    value={listSearchQuery}
                    onChange={(e) => setListSearchQuery(e.target.value)}
                    autoComplete="off"
                  />
                </div>
                <div className="fw-workflow-project-tags" role="tablist" aria-label="Filtrer par projet">
                  <button
                    type="button"
                    role="tab"
                    aria-selected={projectFilter === 'all'}
                    className={`fw-project-tag${projectFilter === 'all' ? ' fw-project-tag--active' : ''}`}
                    onClick={() => setProjectFilter('all')}
                  >
                    Tous
                  </button>
                  {filterProjectOptions.map((p) => (
                    <button
                      key={String(p.id)}
                      type="button"
                      role="tab"
                      aria-selected={projectFilter === String(p.id)}
                      title={p.organizationName ? `${p.name} — ${p.organizationName}` : p.name}
                      className={`fw-project-tag${projectFilter === String(p.id) ? ' fw-project-tag--active' : ''}`}
                      onClick={() => setProjectFilter(String(p.id))}
                    >
                      {p.tagLabel ?? p.name}
                      {isAdmin && p.organizationName && p.tagLabel === p.name ? (
                        <span className="fw-project-tag-org muted"> · {p.organizationName}</span>
                      ) : null}
                    </button>
                  ))}
                </div>
              </div>

              {visibleWebhookItems.length === 0 ? (
                <div className="fw-empty">
                  <h3>Aucun workflow ne correspond</h3>
                  <p>Affinez la recherche, changez l’onglet projet ou créez un workflow dans ce projet.</p>
                </div>
              ) : (
                <div className="fw-card-grid fw-card-grid--workflows">
                  {visibleWebhookItems.map((row) => {
                    const projLabel = resolveWebhookProjectLabel(row, projects);
                    const urlDisplay = truncateMiddle(row.ingressUrl, 52);
                    const lastExecVerified = lastExecutionVerifiedFromRow(row);
                    const lastLogFailed = row.lastLogStatus === 'error';
                    const errSnippet =
                      typeof row.lastLogErrorDetail === 'string' && row.lastLogErrorDetail
                        ? row.lastLogErrorDetail.length > 140
                          ? `${row.lastLogErrorDetail.slice(0, 137)}…`
                          : row.lastLogErrorDetail
                        : '';
                    return (
                      <article
                        key={row.id}
                        className={`fw-workflow-card${lastLogFailed ? ' fw-workflow-card--last-failed' : ''}`}
                      >
                        <div className="fw-workflow-card__body">
                          <div className="fw-workflow-card__toolbar">
                            <div className="fw-workflow-card__chips">
                              <span className={`fw-workflow-card__status ${row.active ? 'is-on' : 'is-off'}`}>
                                {row.active ? 'Actif' : 'Inactif'}
                              </span>
                              <span
                                className={`fw-workflow-card__status ${row.lifecycle === 'draft' ? 'is-draft' : 'is-production'}`}
                              >
                                {row.lifecycle === 'draft' ? 'Brouillon' : 'Production'}
                              </span>
                              <span className="fw-workflow-card__verified-wrap" title="Dernière exécution">
                                <LastExecutionVerifiedSwitch verified={lastExecVerified} compact />
                              </span>
                              {projLabel !== '—' ? (
                                <span className="fw-workflow-card__project">{projLabel}</span>
                              ) : (
                                <span className="fw-workflow-card__project fw-workflow-card__project--muted">
                                  Sans projet
                                </span>
                              )}
                            </div>
                            <WebhookActionsMenu
                              menuKey={row.id}
                              menuOpen={webhookCardMenuOpenId}
                              onMenuOpenChange={setWebhookCardMenuOpenId}
                              triggerVariant="dots"
                              showFiche
                              onFiche={() => openDetail(row)}
                              onLogs={() => openLogs(row.id)}
                              onEdit={() => startEdit(row)}
                              onDuplicate={() => void duplicateWebhook(row)}
                              onDelete={() => void remove(row.id)}
                              duplicateDisabled={saving || (user.subscription != null && !user.subscription.canCreateWebhook)}
                              duplicateTitle={
                                user.subscription != null && !user.subscription.canCreateWebhook
                                  ? 'Limite de workflows atteinte pour votre forfait'
                                  : 'Dupliquer avec le suffixe « copy »'
                              }
                            />
                          </div>
                          {lastLogFailed ? (
                            <div className="fw-workflow-card__alert" role="status">
                              <strong>Dernier envoi en échec</strong>
                              {errSnippet ? <span className="fw-workflow-card__alert-detail">{errSnippet}</span> : null}
                              <button
                                type="button"
                                className="btn secondary small fw-workflow-card__alert-logs"
                                onClick={() => openLogs(row.id)}
                              >
                                Voir les journaux
                              </button>
                            </div>
                          ) : null}
                          <h3 className="fw-workflow-card__title">
                            <button type="button" className="fw-workflow-card__title-link" onClick={() => openDetail(row)}>
                              {row.name}
                            </button>
                          </h3>
                          {row.description ? (
                            <p className="fw-workflow-card__desc">{row.description}</p>
                          ) : null}
                          <p className="fw-workflow-card__stat-line">
                            <span className="fw-workflow-card__stat-num">{row.logsCount ?? 0}</span>
                            exécution{(row.logsCount ?? 0) !== 1 ? 's' : ''} enregistrée
                            {(row.logsCount ?? 0) !== 1 ? 's' : ''}
                          </p>
                          <div className="fw-workflow-card__preview" aria-label="Aperçu du flux">
                            <WebhookFlowDiagram actions={row.actions} />
                          </div>
                          <div
                            className="fw-url-box fw-url-box--compact fw-url-box--workflow-card"
                            title={row.ingressUrl}
                          >
                            <code className="fw-url-code">{urlDisplay}</code>
                            <button type="button" className="fw-btn-ghost" onClick={() => copyUrl(row.ingressUrl)}>
                              Copier
                            </button>
                          </div>
                        </div>
                        <div className="fw-workflow-card__footer">
                          <button
                            type="button"
                            className="fw-btn-primary fw-workflow-card__edit"
                            onClick={() => startEdit(row)}
                          >
                            Éditer
                          </button>
                        </div>
                      </article>
                    );
                  })}
                </div>
              )}
            </>
          )}
          </div>
        </div>
      )}

      {view === 'detail' && detailWebhook ? (
        <div className="users-shell fw-workflow-detail-page">
          <header className="users-hero users-hero--minimal">
            <div className="users-hero-text">
              <button type="button" className="fw-back fw-detail-page-back" onClick={goToList}>
                ← Tous les workflows
              </button>
              <h1 className="users-hero-title">
                <i className="fa-solid fa-diagram-project" aria-hidden />
                <span>{detailWebhook.name}</span>
              </h1>
            </div>
            <div className="users-hero-actions fw-projects-hero-actions fw-detail-page-actions">
              <button
                type="button"
                className="btn fw-btn-primary-detail"
                onClick={() => startEdit(detailWebhook)}
              >
                Éditer le workflow
              </button>
              <button type="button" className="btn secondary" onClick={() => openLogs(detailWebhook.id)}>
                Journaux
              </button>
              <WebhookActionsMenu
                menuKey={detailWebhook.id}
                menuOpen={detailActionsMenuOpenId}
                onMenuOpenChange={setDetailActionsMenuOpenId}
                triggerVariant="dots"
                showFiche={false}
                onLogs={() => openLogs(detailWebhook.id)}
                onEdit={() => startEdit(detailWebhook)}
                onDuplicate={() => void duplicateWebhook(detailWebhook)}
                onDelete={() => void remove(detailWebhook.id)}
                duplicateDisabled={saving || (user.subscription != null && !user.subscription.canCreateWebhook)}
                duplicateTitle={
                  user.subscription != null && !user.subscription.canCreateWebhook
                    ? 'Limite de workflows atteinte pour votre forfait'
                    : 'Dupliquer avec le suffixe « copy »'
                }
              />
            </div>
          </header>

          <div className="content-card">
            <div className="fw-detail-shell fw-detail-shell--in-card">
              <div className="fw-detail-page-summary">
                <div className="fw-detail-hero-chips">
                  <span className={`fw-detail-chip ${detailWebhook.active ? 'fw-detail-chip--accent' : ''}`}>
                    {detailWebhook.active ? 'Actif' : 'Inactif'}
                  </span>
                  <span
                    className={`fw-detail-chip ${detailWebhook.lifecycle === 'draft' ? 'fw-detail-chip--draft' : ''}`}
                  >
                    {detailWebhook.lifecycle === 'draft' ? 'Brouillon' : 'En production'}
                  </span>
                  <span className="fw-detail-chip fw-detail-chip--last-exec" title="Basé sur la dernière ligne du journal">
                    <LastExecutionVerifiedSwitch verified={lastExecutionVerifiedFromRow(detailWebhook)} />
                  </span>
                  {typeof detailWebhook.logsCount === 'number' ? (
                    <span className="fw-detail-chip">
                      {detailWebhook.logsCount} exécution{detailWebhook.logsCount !== 1 ? 's' : ''}
                    </span>
                  ) : null}
                  {isAdmin && detailWebhook.organization?.name ? (
                    <span className="fw-detail-chip">Org. {detailWebhook.organization.name}</span>
                  ) : null}
                  {resolveWebhookProjectLabel(detailWebhook, projects) !== '—' ? (
                    <span className="fw-detail-chip">Projet {resolveWebhookProjectLabel(detailWebhook, projects)}</span>
                  ) : null}
                  {detailWebhook.version != null ? (
                    <span className="fw-detail-chip">v.{detailWebhook.version}</span>
                  ) : null}
                </div>
                {detailWebhook.description ? (
                  <p className="fw-detail-desc fw-detail-page-desc">{detailWebhook.description}</p>
                ) : (
                  <p className="fw-detail-desc fw-detail-page-desc">
                    Webhook POST : aperçu du flux, URL et notifications ci-dessous. Les exécutions sont dans les
                    journaux.
                  </p>
                )}
              </div>

            <div className="fw-detail-tabs" role="tablist" aria-label="Sections de la fiche workflow">
              <button
                type="button"
                role="tab"
                id="fw-detail-tab-overview"
                className="fw-detail-tab"
                aria-selected={detailTab === 'overview'}
                aria-controls="fw-detail-panel-overview"
                onClick={() => setDetailTab('overview')}
              >
                Vue d’ensemble
              </button>
              <button
                type="button"
                role="tab"
                id="fw-detail-tab-integration"
                className="fw-detail-tab"
                aria-selected={detailTab === 'integration'}
                aria-controls="fw-detail-panel-integration"
                onClick={() => setDetailTab('integration')}
              >
                Déclencheur &amp; alertes
              </button>
              <button
                type="button"
                role="tab"
                id="fw-detail-tab-history"
                className="fw-detail-tab"
                aria-selected={detailTab === 'history'}
                aria-controls="fw-detail-panel-history"
                onClick={() => setDetailTab('history')}
              >
                Historique (versions)
              </button>
            </div>

            {detailTab === 'overview' ? (
              <div
                id="fw-detail-panel-overview"
                role="tabpanel"
                aria-labelledby="fw-detail-tab-overview"
                className="fw-detail-tab-panel"
              >
                <div className="fw-detail-kpi-grid">
                  <div className="fw-detail-kpi">
                    <p className="fw-detail-kpi-value">{detailWebhook.logsCount ?? '—'}</p>
                    <p className="fw-detail-kpi-label">Exécutions enregistrées</p>
                  </div>
                  <div className="fw-detail-kpi">
                    <p className="fw-detail-kpi-value">{detailWebhook.version ?? '—'}</p>
                    <p className="fw-detail-kpi-label">Version config.</p>
                  </div>
                  <div className="fw-detail-kpi">
                    <p className="fw-detail-kpi-value">
                      {resolveWebhookProjectLabel(detailWebhook, projects)}
                    </p>
                    <p className="fw-detail-kpi-label">Projet</p>
                  </div>
                </div>
                <h3 className="fw-detail-section-title">Schéma du workflow</h3>
                <WebhookFlowDiagram actions={detailWebhook.actions} />
              </div>
            ) : null}

            {detailTab === 'integration' ? (
              <div
                id="fw-detail-panel-integration"
                role="tabpanel"
                aria-labelledby="fw-detail-tab-integration"
                className="fw-detail-tab-panel"
              >
                <h3 className="fw-detail-section-title">URL du déclencheur (POST)</h3>
                <p className="muted small" style={{ marginTop: 0 }}>
                  Formulaire ou client HTTP : <code>application/x-www-form-urlencoded</code>,{' '}
                  <code>multipart/form-data</code> ou <code>application/json</code>.
                </p>
                <div className="fw-url-box fw-url-box--full" title={detailWebhook.ingressUrl}>
                  <code className="fw-url-code">{detailWebhook.ingressUrl}</code>
                  <button type="button" className="fw-btn-ghost" onClick={() => copyUrl(detailWebhook.ingressUrl)}>
                    Copier
                  </button>
                </div>
                <WebhookDetailNotificationsPanel webhook={detailWebhook} />
              </div>
            ) : null}

            {detailTab === 'history' ? (
              <div
                id="fw-detail-panel-history"
                role="tabpanel"
                aria-labelledby="fw-detail-tab-history"
                className="fw-detail-tab-panel"
              >
                <h3 className="fw-detail-section-title">Historique des modifications</h3>
                <p className="muted small" style={{ marginTop: 0 }}>
                  Traçabilité des changements de configuration (pas des exécutions — voir « Journaux »).
                </p>
                {detailWebhookAuditLoading ? <p className="muted">Chargement…</p> : null}
                {!detailWebhookAuditLoading && (detailWebhookAudit?.items?.length ?? 0) === 0 ? (
                  <p className="muted small">Aucune entrée.</p>
                ) : null}
                {(detailWebhookAudit?.items?.length ?? 0) > 0 ? (
                  <ul className="fw-audit-list" style={{ listStyle: 'none', padding: 0, margin: '0.5rem 0 0' }}>
                    {detailWebhookAudit.items.map((entry) => (
                      <li
                        key={entry.id}
                        className="fw-audit-item"
                        style={{ borderBottom: '1px solid var(--input-border)', padding: '0.5rem 0' }}
                      >
                        <WorkflowAuditEntry entry={entry} />
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : null}
            </div>
          </div>
        </div>
      ) : null}

      {view === 'editor' && (
        <div className="users-shell fw-workflow-editor-page">
          <header className="users-hero users-hero--minimal">
            <div className="users-hero-text">
              <button type="button" className="fw-back fw-detail-page-back" onClick={cancelEdit}>
                {isCreate ? '← Workflows' : '← Fiche workflow'}
              </button>
              <h1 className="users-hero-title">
                <i className="fa-solid fa-diagram-project" aria-hidden />
                <span>{isCreate ? 'Nouveau workflow' : `Modifier « ${form.name} »`}</span>
              </h1>
              <p className="users-hero-sub muted">
                {form.webhookVersion != null ? (
                  <>
                    Version <strong>{form.webhookVersion}</strong>
                    {' · '}
                  </>
                ) : null}
                Déclencheur : webhook POST · {form.actions?.length ?? 0} action
                {(form.actions?.length ?? 0) !== 1 ? 's' : ''} (Mailjet et/ou connecteurs tiers)
              </p>
            </div>
            <div className="users-hero-actions fw-projects-hero-actions fw-editor-hero-tools">
              <label className="fw-webhook-active-toggle">
                <input
                  type="checkbox"
                  role="switch"
                  checked={!!form.active}
                  onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))}
                  aria-label={form.active ? 'Désactiver le webhook' : 'Activer le webhook'}
                />
                <span>{form.active ? 'Webhook actif' : 'Webhook désactivé'}</span>
              </label>
            </div>
          </header>

          <div className="content-card">
            {saveSuccessMessage ? (
              <div className="fw-save-success-banner" role="status">
                {saveSuccessMessage}
              </div>
            ) : null}
            <div className="fw-editor-layout">
              <nav className="fw-editor-rail" role="tablist" aria-label="Sections de l’éditeur">
                {EDITOR_NAV.filter((s) => (isCreate ? s.id !== 'history' : true)).map((s) => (
                  <button
                    key={s.id}
                    id={`fw-editor-tab-${s.id}`}
                    type="button"
                    role="tab"
                    aria-selected={editorNavSection === s.id}
                    tabIndex={editorNavSection === s.id ? 0 : -1}
                    className={`fw-rail-step ${editorNavSection === s.id ? 'fw-rail-active' : ''}`}
                    onClick={() => setEditorNavSection(s.id)}
                  >
                    {s.label}
                  </button>
                ))}
              </nav>
              <div className="fw-editor-column">{editorForm}</div>
            </div>
          </div>
        </div>
      )}

      {view === 'logs' && (
        <div className="users-shell fw-workflow-logs-page">
          <header className="users-hero users-hero--minimal">
            <div className="users-hero-text">
              <div className="fw-logs-page-back-row">
                <button
                  type="button"
                  className="fw-back"
                  onClick={() => logsWebhookId != null && goToDetailById(logsWebhookId)}
                >
                  ← Fiche workflow
                </button>
                <button type="button" className="fw-back fw-back--muted" onClick={goToList}>
                  Tous les workflows
                </button>
              </div>
              <h1 className="users-hero-title">
                <i className="fa-solid fa-clipboard-list" aria-hidden />
                <span>Journaux · {logsWebhookName}</span>
              </h1>
              <p className="users-hero-sub muted">
                Historique des réceptions pour ce webhook. Sélectionnez une ligne pour lire le détail et chaque action.
              </p>
            </div>
          </header>

          <div className="content-card">
            <div className="fw-logs-layout">
            <div className="fw-logs-list">
              <div className="fw-logs-list-head">Exécutions récentes</div>
              {logs?.error ? (
                <p className="error" style={{ padding: '1rem' }}>
                  {logs.error}
                </p>
              ) : null}
              {logs?.items && logs.items.length === 0 ? (
                <div className="fw-muted-box">Aucune exécution enregistrée.</div>
              ) : null}
              {logs?.items?.map((l) => (
                <button
                  key={l.id}
                  type="button"
                  className={`fw-run-row ${selectedLogId === l.id ? 'fw-run-selected' : ''}`}
                  onClick={() => void openLogDetail(l.id)}
                >
                  <div>
                    <div className="fw-run-meta">
                      <strong>#{l.id}</strong> ·{' '}
                      {l.receivedAt ? new Date(l.receivedAt).toLocaleString('fr-FR') : '—'}
                    </div>
                    <div className="fw-run-meta">
                      Statut <strong>{l.status}</strong>
                      {l.actionsSummary
                        ? ` · ${l.actionsSummary.succeeded}/${l.actionsSummary.total} actions OK`
                        : ''}
                    </div>
                  </div>
                  <span
                    className={`badge ${
                      l.status === 'sent'
                        ? 'ok'
                        : l.status === 'error'
                          ? 'danger'
                          : l.status === 'skipped'
                            ? 'neutral'
                            : 'warn'
                    }`}
                  >
                    {l.status}
                  </span>
                </button>
              ))}
            </div>

            <div className="fw-logs-detail">
              {logDetailLoading ? <p className="muted">Chargement…</p> : null}
              {logDetailError ? <p className="error">{logDetailError}</p> : null}
              {!logDetailLoading && !logDetail && !logDetailError ? (
                <div className="fw-muted-box">Sélectionnez une exécution pour afficher le détail.</div>
              ) : null}

              {logDetail ? (
                <>
                  <h3>Exécution #{logDetail.id}</h3>
                  <div className="log-detail-meta">
                    <span>
                      Reçu :{' '}
                      {logDetail.receivedAt
                        ? new Date(logDetail.receivedAt).toLocaleString('fr-FR', {
                            dateStyle: 'short',
                            timeStyle: 'medium',
                          })
                        : '—'}
                    </span>
                    <span>
                      Statut : <strong>{logDetail.status}</strong>
                    </span>
                    {logDetail.durationMs != null ? <span>{logDetail.durationMs} ms</span> : null}
                    {logDetail.clientIp ? <span className="mono">IP {logDetail.clientIp}</span> : null}
                  </div>
                  {logDetail.userAgent ? (
                    <p className="muted small" style={{ margin: '0 0 0.5rem' }}>
                      User-Agent : <span className="mono">{logDetail.userAgent}</span>
                    </p>
                  ) : null}
                  {logDetail.errorDetail ? (
                    <p className="error" style={{ margin: '0 0 0.75rem' }}>
                      {logDetail.errorDetail}
                    </p>
                  ) : null}

                  <KeyValueBlock
                    title="Champs reçus (après parsing)"
                    record={logDetail.parsedInput}
                    emptyText="Aucun champ parsé enregistré."
                  />

                  {Array.isArray(logDetail.actionLogs) && logDetail.actionLogs.length > 0 ? (
                    <div className="log-kv-block">
                      <h4 className="log-kv-title">Par action</h4>
                      {logDetail.actionLogs.map((al, i) => (
                        <div
                          key={al.id ?? i}
                          style={{
                            borderLeft: '3px solid var(--coral)',
                            paddingLeft: '0.75rem',
                            marginBottom: '1rem',
                          }}
                        >
                          <p className="small muted" style={{ margin: '0 0 0.35rem' }}>
                            Action {i + 1}
                            {al.formWebhookActionId != null ? ` · config #${al.formWebhookActionId}` : ''}
                            {al.actionType ? ` · ${al.actionType}` : ''} — <strong>{al.status}</strong>
                            {al.durationMs != null ? ` · ${al.durationMs} ms` : ''}
                          </p>
                          {al.errorDetail ? <p className="error small">{al.errorDetail}</p> : null}
                          {al.toEmail ? (
                            <p className="small" style={{ margin: '0.25rem 0' }}>
                              À : {al.toEmail}
                            </p>
                          ) : null}
                          <KeyValueBlock
                            title="Variables (cette action)"
                            record={al.variablesSent}
                            emptyText="—"
                          />
                          {al.mailjetMessageId ? (
                            <p className="mono small" style={{ margin: '0.35rem 0' }}>
                              Message id : {al.mailjetMessageId}
                            </p>
                          ) : null}
                          {al.mailjetResponseBody ? (
                            <pre className="log-kv-pre small">
                              {prettyJsonMaybe(al.mailjetResponseBody) ?? al.mailjetResponseBody}
                            </pre>
                          ) : null}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="muted small">Pas de détail d’action (échec très tôt ?).</p>
                  )}

                  {logDetail.rawBody ? (
                    <div className="log-kv-block">
                      <h4 className="log-kv-title">Corps brut</h4>
                      <pre className="log-raw-body">{logDetail.rawBody}</pre>
                    </div>
                  ) : null}
                </>
              ) : null}
            </div>
          </div>
          </div>
        </div>
      )}
    </section>
  );
}
