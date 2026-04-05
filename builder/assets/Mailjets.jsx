import { useCallback, useEffect, useState } from 'react';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export default function Mailjets({ user }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [organizations, setOrganizations] = useState([]);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState({
    name: '',
    apiKeyPublic: '',
    apiKeyPrivate: '',
    organizationId: '',
  });
  const [saving, setSaving] = useState(false);

  const loadOrganizations = useCallback(async () => {
    if (!isAdmin) return;
    const res = await fetch('/api/organizations', { credentials: 'include' });
    const data = await parseJson(res);
    if (res.ok && Array.isArray(data)) setOrganizations(data);
  }, [isAdmin]);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch('/api/mailjets', { credentials: 'include' });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Erreur chargement');
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
    void loadOrganizations();
  }, [loadOrganizations]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const resetForm = () => {
    setForm({ name: '', apiKeyPublic: '', apiKeyPrivate: '', organizationId: '' });
  };

  const startCreate = () => {
    setEditingId('new');
    resetForm();
  };

  const startEdit = async (row) => {
    setError('');
    const res = await fetch(`/api/mailjets/${row.id}`, { credentials: 'include' });
    const data = await parseJson(res);
    if (!res.ok) {
      setError(data?.error ?? 'Chargement impossible');
      return;
    }
    setEditingId(row.id);
    setForm({
      name: data.name ?? '',
      apiKeyPublic: data.apiKeyPublic ?? '',
      apiKeyPrivate: data.apiKeyPrivate ?? '',
      organizationId: data.organization?.id != null ? String(data.organization.id) : '',
    });
  };

  const cancelEdit = () => {
    setEditingId(null);
    resetForm();
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const body = {
        name: form.name,
        apiKeyPublic: form.apiKeyPublic,
        apiKeyPrivate: form.apiKeyPrivate,
      };
      if (isAdmin) body.organizationId = Number(form.organizationId);
      const res = await fetch('/api/mailjets', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? (data?.fields ? JSON.stringify(data.fields) : 'Création impossible'));
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
    try {
      const body = {
        name: form.name,
        apiKeyPublic: form.apiKeyPublic,
        apiKeyPrivate: form.apiKeyPrivate,
      };
      if (isAdmin && form.organizationId !== '') {
        body.organizationId = Number(form.organizationId);
      }
      const res = await fetch(`/api/mailjets/${editingId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? (data?.fields ? JSON.stringify(data.fields) : 'Mise à jour impossible'));
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
    if (!window.confirm('Supprimer cette configuration Mailjet ?')) return;
    setError('');
    try {
      const res = await fetch(`/api/mailjets/${id}`, { method: 'DELETE', credentials: 'include' });
      if (!res.ok && res.status !== 204) {
        const data = await parseJson(res);
        setError(data?.error ?? 'Suppression impossible');
        return;
      }
      cancelEdit();
      await refresh();
    } catch {
      setError('Erreur réseau');
    }
  };

  if (loading) {
    return <p className="muted">Configurations Mailjet…</p>;
  }

  const noOrgUser = !isAdmin && !user.organization;

  return (
    <section className="org-section">
      <h2>Configurations Mailjet</h2>
      {error ? <p className="error">{error}</p> : null}

      {noOrgUser ? (
        <p className="muted">Rattachez votre compte à une organisation pour gérer les clés Mailjet.</p>
      ) : null}

      {!noOrgUser && editingId !== 'new' && (
        <button type="button" className="btn secondary small" onClick={startCreate}>
          Nouvelle configuration
        </button>
      )}

      {!noOrgUser && editingId === 'new' && (
        <form className="org-form mailjet-form" onSubmit={(e) => void submitCreate(e)}>
          <h3>Création</h3>
          {isAdmin && (
            <label className="field">
              <span>Organisation</span>
              <select
                value={form.organizationId}
                onChange={(e) => setForm((f) => ({ ...f, organizationId: e.target.value }))}
                required
              >
                <option value="">— Choisir —</option>
                {organizations.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name} (#{o.id})
                  </option>
                ))}
              </select>
            </label>
          )}
          <label className="field">
            <span>Nom</span>
            <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
          </label>
          <label className="field">
            <span>Clé API publique</span>
            <input
              value={form.apiKeyPublic}
              onChange={(e) => setForm((f) => ({ ...f, apiKeyPublic: e.target.value }))}
              required
              autoComplete="off"
            />
          </label>
          <label className="field">
            <span>Clé API privée</span>
            <input
              type="password"
              value={form.apiKeyPrivate}
              onChange={(e) => setForm((f) => ({ ...f, apiKeyPrivate: e.target.value }))}
              required
              autoComplete="off"
            />
          </label>
          <div className="row">
            <button type="submit" className="btn" disabled={saving}>
              {saving ? '…' : 'Créer'}
            </button>
            <button type="button" className="btn secondary" onClick={cancelEdit} disabled={saving}>
              Annuler
            </button>
          </div>
        </form>
      )}

      {!noOrgUser && editingId !== null && editingId !== 'new' && (
        <form className="org-form mailjet-form" onSubmit={(e) => void submitUpdate(e)}>
          <h3>Modifier #{editingId}</h3>
          {isAdmin && (
            <label className="field">
              <span>Organisation</span>
              <select
                value={form.organizationId}
                onChange={(e) => setForm((f) => ({ ...f, organizationId: e.target.value }))}
                required
              >
                {organizations.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name} (#{o.id})
                  </option>
                ))}
              </select>
            </label>
          )}
          <label className="field">
            <span>Nom</span>
            <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
          </label>
          <label className="field">
            <span>Clé API publique</span>
            <input
              value={form.apiKeyPublic}
              onChange={(e) => setForm((f) => ({ ...f, apiKeyPublic: e.target.value }))}
              required
              autoComplete="off"
            />
          </label>
          <label className="field">
            <span>Clé API privée</span>
            <input
              type="password"
              value={form.apiKeyPrivate}
              onChange={(e) => setForm((f) => ({ ...f, apiKeyPrivate: e.target.value }))}
              required
              autoComplete="off"
            />
          </label>
          <div className="row">
            <button type="submit" className="btn" disabled={saving}>
              {saving ? '…' : 'Enregistrer'}
            </button>
            <button type="button" className="btn secondary" onClick={cancelEdit} disabled={saving}>
              Annuler
            </button>
          </div>
        </form>
      )}

      {!noOrgUser && (
        <div className="org-table-wrap">
          <table className="org-table mailjet-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nom</th>
                {isAdmin ? <th>Organisation</th> : null}
                <th>Clé publique</th>
                <th>Clé privée</th>
                <th>Créé le</th>
                <th>Par</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id}>
                  <td>{row.id}</td>
                  <td>{row.name}</td>
                  {isAdmin ? <td>{row.organization ? row.organization.name : '—'}</td> : null}
                  <td className="mono truncate" title={row.apiKeyPublic}>
                    {row.apiKeyPublic}
                  </td>
                  <td className="mono truncate" title={row.apiKeyPrivate}>
                    {row.apiKeyPrivate}
                  </td>
                  <td>{row.createdAt ? new Date(row.createdAt).toLocaleString('fr-FR') : '—'}</td>
                  <td>{row.createdBy ? row.createdBy.email : '—'}</td>
                  <td className="actions">
      <button type="button" className="btn secondary small" onClick={() => void startEdit(row)}>
                      Modifier
                    </button>
                    <button type="button" className="btn danger small" onClick={() => void remove(row.id)}>
                      Supprimer
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
