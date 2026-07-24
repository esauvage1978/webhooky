import { Fragment } from 'react';
import { ACTION_MAILJET } from './formWebhookConstants.js';
import { ACTION_AI } from './workflowBuiltinActionTypes.js';

export function WebhookFlowDiagram({ actions }) {
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
                    : a.actionType === ACTION_AI
                      ? `Fournisseur IA de l’organisation · sortie ${a.pipelineConfig?.outputKey ?? 'last_ai_response'}`
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
