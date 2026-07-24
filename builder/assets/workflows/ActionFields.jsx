import { useEffect, useState } from 'react';
import { ACTION_MAILJET, DEFAULT_AI_PROMPT, FW_ACTION_DRAG_MIME } from './formWebhookConstants.js';
import { ACTION_AI, isBuiltinActionType } from './workflowBuiltinActionTypes.js';
import { isSmsActionType } from '../integrations/serviceConnectionTypes.js';
import { actionCardTitle, defaultAiPipelineConfig } from './formWebhookUtils.js';
import { FwSwitch } from './FwSwitch.jsx';

export function ActionFields({
  action,
  index,
  mailjetConnections,
  serviceConnections,
  integrationActionTypes = [],
  typesMeta = [],
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
  const connectionsForType = isBuiltinActionType(at) ? [] : serviceConnections.filter((s) => s.type === at);
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
                  const aiDefaults = t === ACTION_AI ? defaultAiPipelineConfig() : {};
                  updateAction(index, {
                    actionType: t,
                    serviceConnectionId:
                      t === ACTION_MAILJET
                        ? mailjetConnections[0]
                          ? String(mailjetConnections[0].id)
                          : ''
                        : '',
                    mailjetId: '',
                    ...(t === ACTION_AI ? { variableMapping: '{}', ...aiDefaults } : {}),
                  });
                }}
              >
                <option value={ACTION_MAILJET}>Mailjet — e-mail (template)</option>
                <option value={ACTION_AI}>IA — fournisseur de l’organisation</option>
                {integrationActionTypes.map((t) => (
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
            ) : at === ACTION_AI ? (
              <p className="muted small fw-action-group-note">
                Cette action utilise le fournisseur IA configuré sur l’organisation du workflow. La réponse peut être
                réutilisée par les étapes suivantes avec <code>{`{{data.${action.aiOutputKey || 'ai_response'}}}`}</code>.
              </p>
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
        ) : at === ACTION_AI ? (
          <div className="fw-action-group fw-action-group--ai">
            <h4 className="fw-action-group-title">Prompt IA</h4>
            <div className="fw-action-group-fields fw-action-group-fields--2col">
              <label className="field fw-field--full">
                <span>Prompt libre (placeholders &#123;&#123; champ &#125;&#125; ou &#123;&#123;data.cle&#125;&#125;)</span>
                <textarea
                  rows={7}
                  className="mono-textarea"
                  value={action.aiPromptTemplate ?? ''}
                  onChange={(e) => updateAction(index, { aiPromptTemplate: e.target.value })}
                  placeholder={DEFAULT_AI_PROMPT}
                  spellCheck={false}
                  required={!action.aiPromptId}
                />
              </label>
              <label className="field">
                <span>Clé de sortie pipeline</span>
                <input
                  value={action.aiOutputKey ?? 'ai_response'}
                  onChange={(e) => updateAction(index, { aiOutputKey: e.target.value })}
                  placeholder="ai_response"
                  pattern="[A-Za-z_][A-Za-z0-9_]*"
                />
              </label>
              {action.aiPromptId && !String(action.aiPromptTemplate ?? '').trim() ? (
                <p className="muted small fw-action-group-note">
                  Cette action conserve le prompt système <code>{action.aiPromptId}</code> existant.
                </p>
              ) : (
                <p className="muted small fw-action-group-note">
                  La réponse est aussi disponible dans <code>{'{{data.last_ai_response}}'}</code> pour compatibilité.
                </p>
              )}
            </div>
          </div>
        ) : at === 'pacflow' ? null : (
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
        ) : isSmsActionType(at, typesMeta) ? (
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
            {at === 'pacflow' ? (
              <p className="muted small fw-action-group-note">
                Ce mapping est envoyé tel quel en JSON vers le webhook Pacflow (pas de bloc message).
              </p>
            ) : null}
          </div>
        </div>
      </div>
    </fieldset>
  );
}
