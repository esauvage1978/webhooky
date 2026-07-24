import { FORM_WEBHOOK_AUDIT_ACTION_LABELS, FORM_WEBHOOK_AUDIT_KEY_LABELS } from './formWebhookConstants.js';
import { auditChangedFieldLabels } from './formWebhookUtils.js';

export function WorkflowAuditDiff({ diff }) {
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
export function WorkflowAuditEntry({ entry }) {
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
