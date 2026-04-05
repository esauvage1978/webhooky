import { Fragment, useCallback, useEffect, useState } from 'react';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
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
                <strong>Action — Mailjet {i + 1}</strong>
                <span>
                  Template #{a.mailjetTemplateId}
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

function ActionFields({ action, index, mailjets, updateAction, onRemove, canRemove }) {
  return (
    <fieldset className="fw-action-card mailjet-form">
      <legend>
        <span>Action {index + 1}</span>
      </legend>
      <label className="field">
        <span>Compte Mailjet (clés)</span>
        <select
          value={action.mailjetId}
          onChange={(e) => updateAction(index, { mailjetId: e.target.value })}
          required
        >
          <option value="">—</option>
          {mailjets.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name} (#{m.id})
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span>ID template Mailjet</span>
        <input
          type="number"
          value={action.mailjetTemplateId}
          onChange={(e) => updateAction(index, { mailjetTemplateId: e.target.value })}
          required
        />
      </label>
      <label className="field" style={{ flexDirection: 'row', alignItems: 'center', gap: '0.5rem' }}>
        <input
          type="checkbox"
          checked={!!action.templateLanguage}
          onChange={(e) => updateAction(index, { templateLanguage: e.target.checked })}
        />
        <span>Template language (MJML / dynamique)</span>
      </label>
      <label className="field">
        <span>Mapping JSON — variable Mailjet → champ POST</span>
        <textarea
          rows={5}
          className="mono-textarea"
          value={action.variableMapping}
          onChange={(e) => updateAction(index, { variableMapping: e.target.value })}
          spellCheck={false}
        />
      </label>
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
      <label className="field" style={{ flexDirection: 'row', alignItems: 'center', gap: '0.5rem' }}>
        <input
          type="checkbox"
          checked={action.active !== false}
          onChange={(e) => updateAction(index, { active: e.target.checked })}
        />
        <span>Exécuter cette action à chaque réception</span>
      </label>
      {canRemove ? (
        <button type="button" className="btn secondary small" onClick={() => onRemove(index)}>
          Retirer cette action
        </button>
      ) : null}
    </fieldset>
  );
}

function defaultActionRow() {
  return {
    mailjetId: '',
    mailjetTemplateId: '',
    templateLanguage: true,
    variableMapping: '{\n  "var_modele": "champ_formulaire"\n}',
    recipientEmailPostKey: 'email',
    recipientNamePostKey: '',
    defaultRecipientEmail: '',
    active: true,
  };
}

const emptyForm = {
  name: '',
  description: '',
  organizationId: '',
  active: true,
  notificationEmailSource: 'creator',
  notificationCustomEmail: '',
  notifyOnError: true,
  notifyOnSuccess: false,
  notificationCreatorHint: '',
  actions: [defaultActionRow()],
};

/** `list` | `editor` | `logs` */
export default function FormWebhooks({ user }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [items, setItems] = useState([]);
  const [mailjets, setMailjets] = useState([]);
  const [orgs, setOrgs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [view, setView] = useState('list');
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [logsWebhookId, setLogsWebhookId] = useState(null);
  const [logsWebhookName, setLogsWebhookName] = useState('');
  const [logs, setLogs] = useState(null);
  const [logDetail, setLogDetail] = useState(null);
  const [selectedLogId, setSelectedLogId] = useState(null);
  const [logDetailLoading, setLogDetailLoading] = useState(false);
  const [logDetailError, setLogDetailError] = useState('');

  const loadRefs = useCallback(async () => {
    const [rMj, rOrg] = await Promise.all([
      fetch('/api/mailjets', { credentials: 'include' }),
      isAdmin ? fetch('/api/organizations', { credentials: 'include' }) : Promise.resolve(null),
    ]);
    const dMj = await parseJson(rMj);
    if (rMj.ok && Array.isArray(dMj)) setMailjets(dMj);
    if (isAdmin && rOrg) {
      const dO = await parseJson(rOrg);
      if (rOrg.ok && Array.isArray(dO)) setOrgs(dO);
    }
  }, [isAdmin]);

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
    void refresh();
  }, [refresh]);

  const goToList = () => {
    setView('list');
    setEditingId(null);
    setForm(emptyForm);
    setLogsWebhookId(null);
    setLogsWebhookName('');
    setLogs(null);
    setLogDetail(null);
    setSelectedLogId(null);
    setLogDetailError('');
  };

  const openLogs = async (webhookId) => {
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
    const a0 = defaultActionRow();
    if (mailjets[0]) a0.mailjetId = String(mailjets[0].id);
    setForm({
      ...emptyForm,
      ...(isAdmin && orgs[0] ? { organizationId: String(orgs[0].id) } : {}),
      notificationCreatorHint: user?.email ?? '',
      actions: [a0],
    });
    setEditingId('new');
    setView('editor');
    setError('');
  };

  const startEdit = (row) => {
    const raw = Array.isArray(row.actions) && row.actions.length > 0 ? row.actions : [];
    const actions =
      raw.length > 0
        ? raw.map((a) => ({
            mailjetId: a.mailjetId != null ? String(a.mailjetId) : '',
            mailjetTemplateId: a.mailjetTemplateId != null ? String(a.mailjetTemplateId) : '',
            templateLanguage: !!a.templateLanguage,
            variableMapping: JSON.stringify(a.variableMapping ?? {}, null, 2),
            recipientEmailPostKey: a.recipientEmailPostKey ?? '',
            recipientNamePostKey: a.recipientNamePostKey ?? '',
            defaultRecipientEmail: a.defaultRecipientEmail ?? '',
            active: a.active !== false,
          }))
        : [defaultActionRow()];
    setForm({
      name: row.name,
      description: row.description ?? '',
      ...(isAdmin ? { organizationId: row.organizationId != null ? String(row.organizationId) : '' } : {}),
      active: !!row.active,
      notificationEmailSource: row.notificationEmailSource === 'custom' ? 'custom' : 'creator',
      notificationCustomEmail: row.notificationCustomEmail ?? '',
      notifyOnError: row.notifyOnError !== false,
      notifyOnSuccess: !!row.notifyOnSuccess,
      notificationCreatorHint: row.createdByEmail ?? user?.email ?? '',
      actions,
    });
    setEditingId(row.id);
    setView('editor');
    setError('');
  };

  const cancelEdit = () => {
    goToList();
  };

  const bodyFromForm = () => {
    if (!form.actions?.length) {
      throw new SyntaxError('Au moins une action est requise');
    }
    const actions = form.actions.map((a, idx) => {
      let mapping = {};
      try {
        mapping = JSON.parse(a.variableMapping || '{}');
      } catch {
        throw new SyntaxError(`Action #${idx + 1} : variableMapping JSON invalide`);
      }
      return {
        mailjetId: Number(a.mailjetId),
        mailjetTemplateId: Number(a.mailjetTemplateId),
        templateLanguage: !!a.templateLanguage,
        variableMapping: mapping,
        recipientEmailPostKey: a.recipientEmailPostKey || null,
        recipientNamePostKey: a.recipientNamePostKey || null,
        defaultRecipientEmail: a.defaultRecipientEmail || null,
        active: !!a.active,
        sortOrder: idx,
      };
    });
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
      notifyOnSuccess: !!form.notifyOnSuccess,
      actions,
    };
    if (isAdmin) body.organizationId = Number(form.organizationId);
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
      const a = defaultActionRow();
      if (mailjets[0]) a.mailjetId = String(mailjets[0].id);
      return { ...f, actions: [...(f.actions || []), a] };
    });
  };

  const removeActionRow = (index) => {
    setForm((f) => {
      const next = [...(f.actions || [])];
      if (next.length <= 1) return f;
      next.splice(index, 1);
      return { ...f, actions: next };
    });
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const body = bodyFromForm();
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
      cancelEdit();
      await refresh();
    } catch (err) {
      setError(err?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const submitUpdate = async (e) => {
    e.preventDefault();
    if (editingId === 'new' || editingId == null) return;
    setSaving(true);
    setError('');
    try {
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
      cancelEdit();
      await refresh();
    } catch (err) {
      setError(err?.message ?? 'Erreur');
    } finally {
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
    if (logsWebhookId === id) {
      goToList();
    }
    await refresh();
  };

  const copyUrl = (url) => {
    void navigator.clipboard.writeText(url);
  };

  if (!user.organization && !isAdmin) {
    return (
      <section className="org-section fw-app">
        <h2>Webhooks formulaires</h2>
        <p className="muted">Rattachez-vous à une organisation pour configurer des webhooks.</p>
      </section>
    );
  }

  if (loading && view === 'list') {
    return (
      <section className="org-section fw-app">
        <div className="admin-loading-inner" style={{ padding: '3rem' }}>
          <div className="admin-spinner" />
          <p className="muted">Chargement des workflows…</p>
        </div>
      </section>
    );
  }

  const isCreate = editingId === 'new';

  const editorForm = (
    <form
      className="fw-editor-stack mailjet-form"
      onSubmit={(e) => (isCreate ? void submitCreate(e) : void submitUpdate(e))}
      style={{ border: 'none', background: 'transparent', padding: 0, margin: 0, maxWidth: 'none' }}
    >
      {error ? <p className="error" style={{ marginBottom: '1rem' }}>{error}</p> : null}

      <div className="fw-block fw-block-trigger">
        <header className="fw-block-header">
          <span className="fw-block-step">Déclencheur</span>
          <h2>Ce qui démarre le workflow</h2>
          <p className="fw-block-intro">
            Le déclencheur reçoit les données en premier. D’autres types (planification, fichier, etc.) pourront être
            proposés plus tard.
          </p>
        </header>
        <div className="fw-trigger-type-row">
          <span className="fw-type-badge">Webhook · POST entrant</span>
          <span className="muted small">URL unique générée à l’enregistrement, corps formulaire ou JSON.</span>
        </div>
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
          <input value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
        </label>
        <label className="field" style={{ flexDirection: 'row', alignItems: 'center', gap: '0.5rem' }}>
          <input
            type="checkbox"
            checked={form.active}
            onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))}
          />
          <span>Déclencheur actif (l’URL accepte les requêtes)</span>
        </label>

        <div
          className="fw-notifications"
          style={{
            marginTop: '1.25rem',
            paddingTop: '1.15rem',
            borderTop: '1px solid var(--border)',
          }}
        >
          <span className="fw-block-step">Notifications Webhooky</span>
          <p className="muted small" style={{ margin: '0.55rem 0 1rem', lineHeight: 1.5 }}>
            Recevoir un récapitulatif d’exécution (expéditeur{' '}
            <code className="mono" style={{ fontSize: '0.85em' }}>
              contact@Webhooky.fr
            </code>
            , pas de réponse attendue). Site :{' '}
            <a href="https://Webhooky.fr" target="_blank" rel="noopener noreferrer">
              Webhooky.fr
            </a>
            .
          </p>
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
          <label className="field" style={{ flexDirection: 'row', alignItems: 'center', gap: '0.5rem' }}>
            <input
              type="checkbox"
              checked={!!form.notifyOnSuccess}
              onChange={(e) => setForm((f) => ({ ...f, notifyOnSuccess: e.target.checked }))}
            />
            <span>Envoyer un récap en cas de succès complet</span>
          </label>
        </div>
      </div>

      <p className="fw-block-between" role="presentation">
        puis
      </p>

      <div className="fw-block fw-block-actions">
        <header className="fw-block-header">
          <span className="fw-block-step">Actions</span>
          <h2>Ce qui s’exécute ensuite</h2>
          <p className="fw-block-intro">
            Les actions s’enchaînent après un déclenchement réussi. Aujourd’hui : envois d’e-mails via Mailjet ; d’autres
            connecteurs pourront s’ajouter.
          </p>
        </header>
        <div className="fw-actions-type-row">
          <span className="fw-type-badge fw-type-badge-neutral">Action · Mailjet</span>
          <span className="muted small">
            Chaque bloc compte un événement. La réponse HTTP globale n’est positive que si toutes les actions réussissent.
          </span>
        </div>
        {(form.actions || []).map((a, i) => (
          <ActionFields
            key={i}
            action={a}
            index={i}
            mailjets={mailjets}
            updateAction={updateAction}
            onRemove={removeActionRow}
            canRemove={(form.actions || []).length > 1}
          />
        ))}
        <button type="button" className="fw-btn-ghost" style={{ marginBottom: '0.25rem' }} onClick={addActionRow}>
          + Ajouter une action Mailjet
        </button>
      </div>

      <div className="fw-editor-actions-bar row" style={{ marginTop: 0 }}>
        <button type="submit" className="btn" disabled={saving}>
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
        <>
          <header className="fw-hero">
            <div className="fw-hero-text">
              <h1>Workflows webhook</h1>
              <p>
                Construisez un déclencheur HTTP unique et enchaînez des actions Mailjet, comme dans un outil
                d’automatisation. Consultez les exécutions et détaillez chaque action depuis les journaux.
              </p>
            </div>
            <button
              type="button"
              className="fw-btn-primary"
              onClick={startCreate}
              disabled={user.subscription != null && !user.subscription.canCreateWebhook}
            >
              + Nouveau workflow
            </button>
          </header>

          {user.subscription ? (
            <div
              className={`subscription-banner ${
                user.subscription.blockReason || !user.subscription.webhooksOperational
                  ? 'subscription-banner-err'
                  : ''
              }`}
              role="status"
            >
              <p>
                <strong>Forfait :</strong> {user.subscription.planLabel} — {user.subscription.webhookCount ?? 0}
                {user.subscription.maxWebhooks != null ? ` / ${user.subscription.maxWebhooks}` : ''} workflow(s).
                {' — '}
                <strong>Événements :</strong> {user.subscription.eventsConsumed ?? 0} /{' '}
                {user.subscription.eventsAllowance ?? 0}
                {!user.subscription.webhooksOperational ? ' Réceptions refusées pour l’instant.' : ''}
              </p>
              {user.subscription.blockReason ? <p className="error">{user.subscription.blockReason}</p> : null}
            </div>
          ) : null}

          {error ? <p className="error">{error}</p> : null}

          {items.length === 0 ? (
            <div className="fw-empty">
              <h3>Aucun workflow pour l’instant</h3>
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
            </div>
          ) : (
            <div className="fw-card-grid">
              {items.map((row) => (
                <article key={row.id} className="fw-zap-card">
                  <div className="fw-zap-card-head">
                    <h3>{row.name}</h3>
                    <span className={`fw-pill ${row.active ? 'fw-pill-on' : 'fw-pill-off'}`}>
                      {row.active ? 'Actif' : 'Inactif'}
                    </span>
                  </div>
                  <p className="fw-card-stat">
                    <strong>{row.logsCount ?? 0}</strong> exécution{(row.logsCount ?? 0) !== 1 ? 's' : ''} enregistrée
                    {(row.logsCount ?? 0) !== 1 ? 's' : ''}
                  </p>
                  <WebhookFlowDiagram actions={row.actions} />
                  <div className="fw-url-box" title={row.ingressUrl}>
                    <code>{row.ingressUrl}</code>
                    <button type="button" className="fw-btn-ghost" onClick={() => copyUrl(row.ingressUrl)}>
                      Copier
                    </button>
                  </div>
                  <div className="fw-card-actions">
                    <button type="button" className="btn secondary small" onClick={() => void openLogs(row.id)}>
                      Journaux
                    </button>
                    <button type="button" className="btn secondary small" onClick={() => startEdit(row)}>
                      Modifier
                    </button>
                    <button type="button" className="btn danger small" onClick={() => void remove(row.id)}>
                      Supprimer
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </>
      )}

      {view === 'editor' && (
        <>
          <div className="fw-subhead">
            <button type="button" className="fw-back" onClick={cancelEdit}>
              ← Workflows
            </button>
            <h2 className="fw-subhead-title">{isCreate ? 'Nouveau workflow' : `Modifier « ${form.name} »`}</h2>
            <p className="fw-subhead-meta">
              Déclencheur : webhook POST · {form.actions?.length ?? 0} action
              {(form.actions?.length ?? 0) !== 1 ? 's' : ''} (Mailjet pour l’instant)
            </p>
          </div>
          <div className="fw-editor-layout">
            <nav className="fw-editor-rail" aria-label="Étapes">
              <div className="fw-rail-step fw-rail-active">1 · Déclencheur</div>
              <div className="fw-rail-step fw-rail-active">2 · Actions</div>
            </nav>
            <div className="fw-editor-column">{editorForm}</div>
          </div>
        </>
      )}

      {view === 'logs' && (
        <>
          <div className="fw-subhead">
            <button type="button" className="fw-back" onClick={goToList}>
              ← Workflows
            </button>
            <h2 className="fw-subhead-title">Journaux · {logsWebhookName}</h2>
            <p className="fw-subhead-meta">
              Historique des réceptions pour ce webhook. Sélectionnez une ligne pour lire le détail et chaque action.
            </p>
          </div>

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
                  <span className={`badge ${l.status === 'sent' ? 'ok' : l.status === 'error' ? 'danger' : 'warn'}`}>
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
                            {al.formWebhookActionId != null ? ` · config #${al.formWebhookActionId}` : ''} —{' '}
                            <strong>{al.status}</strong>
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
        </>
      )}
    </section>
  );
}
