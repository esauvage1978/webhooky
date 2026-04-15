import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ModalPortal from '../components/ModalPortal.jsx';
import { parseJson } from '../lib/http.js';

const TYPE_LABELS = {
  mailjet: 'Mailjet (e-mail)',
  slack: 'Slack',
  teams: 'Microsoft Teams',
  discord: 'Discord',
  google_chat: 'Google Chat',
  mattermost: 'Mattermost',
  twilio_sms: 'Twilio SMS',
  vonage_sms: 'Vonage SMS',
  messagebird_sms: 'MessageBird SMS',
  telegram: 'Telegram',
  http_webhook: 'HTTP personnalisé',
  pushover: 'Pushover',
};

function typeLabel(id) {
  return TYPE_LABELS[id] ?? id;
}

function defaultConfigJson(type, typesMeta) {
  const meta = typesMeta.find((t) => t.id === type);
  if (meta?.configExampleFilled && Object.keys(meta.configExampleFilled).length > 0) {
    return JSON.stringify(meta.configExampleFilled, null, 2);
  }
  const samples = {
    mailjet: { apiKeyPublic: '', apiKeyPrivate: '' },
    slack: { webhookUrl: 'https://hooks.slack.com/services/...' },
    teams: { webhookUrl: 'https://outlook.office.com/webhook/...' },
    discord: { webhookUrl: 'https://discord.com/api/webhooks/...' },
    google_chat: { webhookUrl: 'https://chat.googleapis.com/v1/spaces/...' },
    mattermost: { webhookUrl: 'https://mattermost.example/hooks/...' },
    twilio_sms: { accountSid: '', authToken: '', fromNumber: '+33…' },
    vonage_sms: { apiKey: '', apiSecret: '', from: 'Marque ou +33…' },
    messagebird_sms: { accessKey: '', originator: 'Marque ou +33…' },
    telegram: { botToken: '', chatId: '' },
    http_webhook: { url: 'https://…', method: 'POST', headers: {} },
    pushover: { appToken: '', userKey: '' },
  };
  return JSON.stringify(samples[type] ?? { note: 'voir la documentation du type' }, null, 2);
}

export default function ServiceConnections({ user, hubTitle, hubDescription, embeddedInTabs = false }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [typesMeta, setTypesMeta] = useState([]);
  const [organizations, setOrganizations] = useState([]);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState({
    type: 'mailjet',
    name: '',
    configJson: '{}',
    organizationId: '',
  });
  const [saving, setSaving] = useState(false);
  /** Modale lecture seule : GET /api/service-connections/{id}/audit */
  const [auditModalId, setAuditModalId] = useState(null);
  const [auditData, setAuditData] = useState(null);
  const [auditLoading, setAuditLoading] = useState(false);
  const openedFromQueryRef = useRef(false);

  const firstTypeId = typesMeta[0]?.id ?? 'mailjet';

  const selectedTypeMeta = useMemo(
    () => typesMeta.find((t) => t.id === form.type) ?? null,
    [typesMeta, form.type],
  );

  const exampleFilledPretty = useMemo(() => {
    const ex = selectedTypeMeta?.configExampleFilled;
    if (!ex || typeof ex !== 'object') return '';
    try {
      return JSON.stringify(ex, null, 2);
    } catch {
      return '';
    }
  }, [selectedTypeMeta]);

  const schemaPretty = useMemo(() => {
    const s = selectedTypeMeta?.configSchema;
    if (!s || typeof s !== 'object') return '';
    try {
      return JSON.stringify(s, null, 2);
    } catch {
      return '';
    }
  }, [selectedTypeMeta]);

  /** Organisation active du compte (sélecteur global) ; pour les admins, évite de forcer organizations[0]. */
  const defaultAdminOrganizationId = useMemo(() => {
    if (!isAdmin) return '';
    if (user.organization?.id != null) return String(user.organization.id);
    if (organizations[0]?.id != null) return String(organizations[0].id);
    return '';
  }, [isAdmin, user.organization?.id, organizations]);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [tRes, lRes] = await Promise.all([
        fetch('/api/service-connections/types', { credentials: 'include' }),
        fetch('/api/service-connections', { credentials: 'include' }),
      ]);
      const tData = await parseJson(tRes);
      if (tRes.ok && tData?.types) setTypesMeta(tData.types);
      const lData = await parseJson(lRes);
      if (!lRes.ok) {
        setError(lData?.error ?? 'Erreur chargement');
        setItems([]);
        return;
      }
      setItems(Array.isArray(lData) ? lData : []);
    } catch {
      setError('Erreur réseau');
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  const loadOrgs = useCallback(async () => {
    if (!isAdmin) return;
    const res = await fetch('/api/organizations', { credentials: 'include' });
    const data = await parseJson(res);
    if (res.ok && Array.isArray(data)) setOrganizations(data);
  }, [isAdmin]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  useEffect(() => {
    void loadOrgs();
  }, [loadOrgs]);

  /** Si la liste des organisations arrive après l’ouverture de la modale, compléter le sélecteur admin. */
  useEffect(() => {
    if (editingId !== 'new' || !isAdmin) return;
    setForm((f) => {
      if (f.organizationId) return f;
      if (!defaultAdminOrganizationId) return f;
      return { ...f, organizationId: defaultAdminOrganizationId };
    });
  }, [editingId, isAdmin, defaultAdminOrganizationId]);

  useEffect(() => {
    const open = editingId !== null;
    if (!open) return;
    const onKey = (e) => {
      if (e.key === 'Escape' && !saving) cancelEdit();
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [editingId, saving]);

  useEffect(() => {
    if (auditModalId == null) {
      setAuditData(null);
      return;
    }
    let cancelled = false;
    setAuditLoading(true);
    void (async () => {
      try {
        const res = await fetch(`/api/service-connections/${auditModalId}/audit`, { credentials: 'include' });
        const data = await parseJson(res);
        if (cancelled) return;
        if (res.ok) setAuditData(data);
        else setAuditData({ error: data?.error ?? 'Erreur' });
      } catch {
        if (!cancelled) setAuditData({ error: 'Erreur réseau' });
      } finally {
        if (!cancelled) setAuditLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [auditModalId]);

  useEffect(() => {
    if (auditModalId == null) return;
    const onKey = (e) => {
      if (e.key === 'Escape') setAuditModalId(null);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [auditModalId]);

  const cancelEdit = () => {
    setEditingId(null);
    setForm({
      type: firstTypeId,
      name: '',
      configJson: defaultConfigJson(firstTypeId, typesMeta),
      organizationId: defaultAdminOrganizationId,
    });
    setError('');
  };

  const startCreate = () => {
    const t = firstTypeId;
    setEditingId('new');
    setForm({
      type: t,
      name: '',
      configJson: defaultConfigJson(t, typesMeta),
      organizationId: defaultAdminOrganizationId,
    });
    setError('');
  };

  const startEdit = useCallback((row) => {
    setEditingId(row.id);
    setForm({
      type: row.type,
      name: row.name,
      configJson: JSON.stringify(row.config ?? {}, null, 2),
      organizationId: row.organizationId != null ? String(row.organizationId) : '',
    });
    setError('');
  }, []);

  /** Lien profond depuis la supervision : /integrations?openConnection=123 */
  useEffect(() => {
    if (loading || openedFromQueryRef.current) return;
    const raw = new URLSearchParams(window.location.search).get('openConnection');
    if (!raw) return;
    const id = Number.parseInt(raw, 10);
    if (!Number.isFinite(id) || id <= 0) return;
    const row = items.find((r) => r.id === id);
    if (row) {
      startEdit(row);
      openedFromQueryRef.current = true;
    }
  }, [loading, items, startEdit]);

  const applyExampleToForm = () => {
    if (!exampleFilledPretty) return;
    setForm((f) => ({ ...f, configJson: exampleFilledPretty }));
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    let config;
    try {
      config = JSON.parse(form.configJson || '{}');
    } catch {
      setError('JSON de configuration invalide');
      setSaving(false);
      return;
    }
    try {
      const body = {
        type: form.type,
        name: form.name.trim(),
        config,
      };
      if (isAdmin) body.organizationId = Number(form.organizationId);
      const res = await fetch('/api/service-connections', {
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
    } catch {
      setError('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const submitUpdate = async (e) => {
    e.preventDefault();
    if (editingId === 'new' || editingId == null) return;
    setSaving(true);
    setError('');
    let config;
    try {
      config = JSON.parse(form.configJson || '{}');
    } catch {
      setError('JSON de configuration invalide');
      setSaving(false);
      return;
    }
    try {
      const body = { type: form.type, name: form.name.trim(), config };
      if (isAdmin) body.organizationId = Number(form.organizationId);
      const res = await fetch(`/api/service-connections/${editingId}`, {
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
    } catch {
      setError('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (id) => {
    if (!window.confirm('Supprimer ce connecteur ?')) return;
    setError('');
    const res = await fetch(`/api/service-connections/${id}`, { method: 'DELETE', credentials: 'include' });
    if (!res.ok && res.status !== 204) {
      const data = await parseJson(res);
      setError(data?.error ?? 'Suppression impossible');
      return;
    }
    cancelEdit();
    await refresh();
  };

  const noOrgUser = !isAdmin && !user.organization;

  const modalOpen = !noOrgUser && (editingId === 'new' || (editingId != null && editingId !== 'new'));

  const canStartCreate = !noOrgUser && typesMeta.length > 0;

  const hasHubHeader = !embeddedInTabs && !!(hubTitle || hubDescription);

  const mainBody = (
    <>
      {loading ? <p className="muted">Chargement des connecteurs…</p> : null}

      {!loading ? (
        <section className="integrations-block">
          {error ? <p className="error">{error}</p> : null}

          {noOrgUser ? (
            <p className="muted">Rattachez votre compte à une organisation pour gérer les connecteurs.</p>
          ) : null}

          {embeddedInTabs && !noOrgUser ? (
            <div className="wp-projects-hero-actions" style={{ marginTop: '0.25rem' }}>
              <button
                type="button"
                className="fw-btn-primary wp-proj-hero-btn"
                onClick={startCreate}
                disabled={loading || !canStartCreate}
                title={loading || canStartCreate || noOrgUser ? undefined : 'Aucun type de service disponible'}
              >
                <i className="fa-solid fa-plus" aria-hidden />
                <span>Nouveau connecteur</span>
              </button>
            </div>
          ) : null}

          {!noOrgUser ? (
            <div className="integrations-block__table-wrap org-table-wrap">
              <table className="org-table mailjet-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Nom</th>
                    <th
                      className="org-table-th-center"
                      title="Déclencheurs (workflows) qui utilisent ce connecteur dans au moins une action"
                    >
                      Workflows
                    </th>
                    <th
                      className="org-table-th-center"
                      title="Nombre total d’actions de workflow liées à ce connecteur"
                    >
                      Actions
                    </th>
                    {isAdmin ? <th>Organisation</th> : null}
                    <th className="org-table-th-actions" aria-label="Actions sur le connecteur" />
                  </tr>
                </thead>
                <tbody>
                  {items.map((row) => (
                    <tr key={row.id}>
                      <td>{row.id}</td>
                      <td>{typeLabel(row.type)}</td>
                      <td>{row.name}</td>
                      <td className="mono-cell">{row.workflowCount ?? 0}</td>
                      <td className="mono-cell">{row.actionCount ?? 0}</td>
                      {isAdmin ? <td>{row.organization?.name ?? '—'}</td> : null}
                      <td className="actions">
                        <div className="org-table-actions-inner">
                          <button
                            type="button"
                            className="btn secondary small org-table-action-btn"
                            onClick={() => startEdit(row)}
                          >
                            <i className="fa-solid fa-pen-to-square" aria-hidden />
                            <span>Modifier</span>
                          </button>
                          <button
                            type="button"
                            className="btn secondary small org-table-action-btn"
                            onClick={() => setAuditModalId(row.id)}
                          >
                            <i className="fa-solid fa-clock-rotate-left" aria-hidden />
                            <span>Historique</span>
                          </button>
                          <button
                            type="button"
                            className="btn danger small org-table-action-btn"
                            onClick={() => void remove(row.id)}
                          >
                            <i className="fa-solid fa-trash-can" aria-hidden />
                            <span>Supprimer</span>
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {items.length === 0 ? <p className="muted small">Aucun connecteur pour l’instant.</p> : null}
            </div>
          ) : null}
        </section>
      ) : null}
    </>
  );

  return (
    <>
      {hasHubHeader ? (
        <>
          <header className="users-hero users-hero--minimal">
            <div className="integrations-hero-intro">
              {hubTitle ? (
                <h1 className="users-hero-title">
                  <i className="fa-solid fa-plug" aria-hidden />
                  <span>{hubTitle}</span>
                </h1>
              ) : null}
              {hubDescription ? <div className="integrations-hero-desc">{hubDescription}</div> : null}
            </div>
            <div className="users-hero-actions wp-projects-hero-actions">
              {!noOrgUser ? (
                <button
                  type="button"
                  className="fw-btn-primary wp-proj-hero-btn"
                  onClick={startCreate}
                  disabled={loading || !canStartCreate}
                  title={
                    loading || canStartCreate || noOrgUser
                      ? undefined
                      : 'Aucun type de service disponible'
                  }
                >
                  <i className="fa-solid fa-plus" aria-hidden />
                  <span>Nouveau service</span>
                </button>
              ) : null}
            </div>
          </header>
          <div className="content-card content-card--integrations-wide">{mainBody}</div>
        </>
      ) : (
        mainBody
      )}

      {modalOpen ? (
        <ModalPortal>
        <div className="sc-modal-backdrop" role="presentation">
          <div
            className="sc-modal"
            role="dialog"
            aria-labelledby="sc-modal-title"
            aria-modal="true"
          >
            <div className="sc-modal-head">
              <h4 id="sc-modal-title">{editingId === 'new' ? 'Nouveau connecteur' : `Modifier #${editingId}`}</h4>
              <button type="button" className="sc-modal-close" aria-label="Fermer" onClick={cancelEdit} disabled={saving}>
                ×
              </button>
            </div>
            <form
              className="org-form mailjet-form sc-modal-form"
              onSubmit={(e) => void (editingId === 'new' ? submitCreate(e) : submitUpdate(e))}
            >
              {isAdmin ? (
                <label className="field">
                  <span>Organisation</span>
                  <select
                    value={form.organizationId}
                    onChange={(e) => setForm((f) => ({ ...f, organizationId: e.target.value }))}
                    required
                  >
                    <option value="">—</option>
                    {organizations.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}
              <label className="field">
                <span>Type de service</span>
                <select
                  value={form.type}
                  onChange={(e) => {
                    const t = e.target.value;
                    setForm((f) => ({ ...f, type: t, configJson: defaultConfigJson(t, typesMeta) }));
                  }}
                >
                  {typesMeta.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field">
                <span>Nom interne</span>
                <input
                  value={form.name}
                  onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                  required
                  placeholder="Ex. Mailjet prod, Slack support…"
                />
              </label>

              {schemaPretty ? (
                <div className="sc-help-block">
                  <span className="sc-help-label">Schéma JSON attendu</span>
                  <pre className="sc-help-pre mono" tabIndex={0}>
                    {schemaPretty}
                  </pre>
                </div>
              ) : null}

              {exampleFilledPretty ? (
                <div className="sc-help-block sc-help-block--example">
                  <div className="sc-help-row">
                    <span className="sc-help-label">Exemple rempli (aide)</span>
                    <button type="button" className="btn secondary small" onClick={applyExampleToForm}>
                      Copier l’exemple dans le formulaire
                    </button>
                  </div>
                  <pre className="sc-help-pre mono" tabIndex={0}>
                    {exampleFilledPretty}
                  </pre>
                  <p className="muted small" style={{ margin: '0.35rem 0 0' }}>
                    Remplacez les valeurs fictives par vos vraies clés ou URLs avant d’enregistrer.
                  </p>
                </div>
              ) : null}

              <label className="field">
                <span>Configuration (JSON)</span>
                <textarea
                  rows={12}
                  className="mono-textarea"
                  value={form.configJson}
                  onChange={(e) => setForm((f) => ({ ...f, configJson: e.target.value }))}
                  spellCheck={false}
                />
              </label>

              <div className="sc-modal-actions">
                <button type="submit" className="btn" disabled={saving}>
                  {saving ? 'Enregistrement…' : editingId === 'new' ? 'Créer' : 'Mettre à jour'}
                </button>
                <button type="button" className="btn secondary" onClick={cancelEdit} disabled={saving}>
                  Annuler
                </button>
              </div>
            </form>
          </div>
        </div>
        </ModalPortal>
      ) : null}

      {auditModalId != null ? (
        <ModalPortal>
        <div className="sc-modal-backdrop" role="presentation">
          <div
            className="sc-modal"
            role="dialog"
            aria-labelledby="sc-audit-title"
            aria-modal="true"
          >
            <div className="sc-modal-head">
              <h4 id="sc-audit-title">Historique · connecteur #{auditModalId}</h4>
              <button type="button" className="sc-modal-close" aria-label="Fermer" onClick={() => setAuditModalId(null)}>
                ×
              </button>
            </div>
            <div className="sc-modal-form">
              <p className="muted small" style={{ marginTop: 0 }}>
                Créations, mises à jour et suppressions sont enregistrées (sans stocker les secrets de configuration :{' '}
                empreinte seulement pour les changements de clés).
              </p>
              {auditLoading ? <p className="muted">Chargement…</p> : null}
              {auditData?.error ? <p className="error">{auditData.error}</p> : null}
              {!auditLoading && !auditData?.error && (auditData?.items?.length ?? 0) === 0 ? (
                <p className="muted">Aucune entrée.</p>
              ) : null}
              {(auditData?.items?.length ?? 0) > 0 ? (
                <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                  {auditData.items.map((entry) => (
                    <li
                      key={entry.id}
                      style={{ borderBottom: '1px solid var(--input-border)', padding: '0.65rem 0' }}
                    >
                      <div>
                        <strong>{entry.action}</strong>
                        {' · '}
                        {entry.occurredAt
                          ? new Date(entry.occurredAt).toLocaleString('fr-FR', {
                              dateStyle: 'short',
                              timeStyle: 'medium',
                            })
                          : '—'}
                      </div>
                      <div className="muted small">{entry.actorEmail ?? '—'}</div>
                      {entry.details?.requestKeys ? (
                        <div className="muted small">Champs : {entry.details.requestKeys.join(', ')}</div>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </div>
        </div>
        </ModalPortal>
      ) : null}
    </>
  );
}
