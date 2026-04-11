import { useCallback, useEffect, useState } from 'react';
import { apiPostJson, parseJson } from '../lib/http.js';

function formatAdminQuotaCell(subscription) {
  if (!subscription) return '—';
  const consumed = subscription.eventsConsumed ?? 0;
  if (subscription.subscriptionExempt) {
    return `${Number(consumed).toLocaleString('fr-FR')} (interne)`;
  }
  const allowance = subscription.eventsAllowance ?? 0;
  return `${Number(consumed).toLocaleString('fr-FR')} / ${Number(allowance).toLocaleString('fr-FR')}`;
}

export default function Organizations({ user, onOrganizationChanged }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editingId, setEditingId] = useState(null);
  const [formName, setFormName] = useState('');
  const [formUserId, setFormUserId] = useState('');
  const [saving, setSaving] = useState(false);
  const [regeneratingOrgId, setRegeneratingOrgId] = useState(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch('/api/organizations', { credentials: 'include' });
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
    void refresh();
  }, [refresh]);

  const startCreate = () => {
    setEditingId('new');
    setFormName('');
    setFormUserId('');
  };

  const startEdit = (row) => {
    setEditingId(row.id);
    setFormName(row.name);
    const firstMemberId = row.members?.[0]?.id;
    setFormUserId(firstMemberId != null ? String(firstMemberId) : '');
  };

  const cancelEdit = () => {
    setEditingId(null);
    setFormName('');
    setFormUserId('');
  };

  const submitCreate = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const body = { name: formName };
      if (formUserId.trim() !== '') body.userId = Number(formUserId);
      const res = await fetch('/api/organizations', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Création impossible');
        return;
      }
      cancelEdit();
      await refresh();
      await onOrganizationChanged?.();
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
      const body = { name: formName };
      if (isAdmin) {
        body.userId = formUserId.trim() === '' ? null : Number(formUserId);
      }
      const res = await fetch(`/api/organizations/${editingId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Mise à jour impossible');
        return;
      }
      cancelEdit();
      await refresh();
      await onOrganizationChanged?.();
    } catch {
      setError('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const regenerateWebhookPrefix = async (row) => {
    if (!isAdmin) return;
    const ok = window.confirm(
      `Régénérer le préfixe webhook pour « ${row.name} » ? Les anciennes URL d’ingress (/webhook/form/…) ne fonctionneront plus tant que les intégrations n’auront pas été mises à jour avec les nouvelles URL des workflows.`,
    );
    if (!ok) return;
    setError('');
    setRegeneratingOrgId(row.id);
    try {
      const res = await apiPostJson(`/api/organizations/${row.id}/regenerate-webhook-prefix`, { body: '{}' });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Régénération impossible');
        return;
      }
      await refresh();
      await onOrganizationChanged?.();
    } catch {
      setError('Erreur réseau');
    } finally {
      setRegeneratingOrgId(null);
    }
  };

  const remove = async (id) => {
    if (!isAdmin) return;
    if (!window.confirm('Supprimer cette organisation ?')) return;
    setError('');
    try {
      const res = await fetch(`/api/organizations/${id}`, {
        method: 'DELETE',
        credentials: 'include',
      });
      if (!res.ok && res.status !== 204) {
        const data = await parseJson(res);
        setError(data?.error ?? 'Suppression impossible');
        return;
      }
      cancelEdit();
      await refresh();
      await onOrganizationChanged?.();
    } catch {
      setError('Erreur réseau');
    }
  };

  if (loading) {
    return (
      <div className="users-shell organizations-page">
        <header className="users-hero users-hero--minimal">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-building" aria-hidden />
            <span>Organisations</span>
          </h1>
        </header>
        <div className="content-card">
          <p className="muted">Organisations…</p>
        </div>
      </div>
    );
  }

  return (
    <div className="users-shell organizations-page org-section">
      <header className="users-hero users-hero--minimal">
        <h1 className="users-hero-title">
          <i className="fa-solid fa-building" aria-hidden />
          <span>Organisations</span>
        </h1>
        {isAdmin && editingId !== 'new' ? (
          <div className="users-hero-actions wp-projects-hero-actions">
            <button type="button" className="btn secondary small" onClick={startCreate}>
              Nouvelle organisation
            </button>
          </div>
        ) : null}
      </header>

      <div className="content-card">
      {error ? <p className="error">{error}</p> : null}

      {!isAdmin && items.length === 0 ? (
        <p className="muted">Aucune organisation associée à votre compte. Contactez un administrateur.</p>
      ) : null}

      {isAdmin && editingId === 'new' && (
        <form className="org-form" onSubmit={(e) => void submitCreate(e)}>
          <h3>Création</h3>
          <label className="field">
            <span>Nom</span>
            <input value={formName} onChange={(e) => setFormName(e.target.value)} required />
          </label>
          <label className="field">
            <span>ID utilisateur lié (optionnel)</span>
            <input
              type="number"
              min="1"
              value={formUserId}
              onChange={(e) => setFormUserId(e.target.value)}
              placeholder="ex. 2"
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

      <div className={`org-table-wrap${isAdmin ? ' org-table-wrap--organizations-admin' : ''}`}>
        <table className="org-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nom</th>
              <th title="Préfixe des jetons d’ingress (/webhook/form/…)">Préfixe webhook</th>
              {isAdmin ? (
                <>
                  <th>Forfait</th>
                  <th title="Événements quota consommés ce mois civil (compteur mensuel)">Évén. mois</th>
                  <th title="Événements consommés (total) / plafond forfait">Quota évén.</th>
                  <th>Membres</th>
                  <th>Projets</th>
                  <th>Webhooks</th>
                </>
              ) : null}
              <th className="org-table-th-actions" aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {items.map((row) => (
              <tr key={row.id}>
                {editingId === row.id ? (
                  <>
                    <td>{row.id}</td>
                    <td colSpan={isAdmin ? 9 : 3}>
                      <form className="inline-form" onSubmit={(e) => void submitUpdate(e)}>
                        {row.webhookPublicPrefix ? (
                          <span className="muted small" style={{ marginRight: '0.75rem' }} title="Non modifiable">
                            Préfixe :{' '}
                            <code className="mono">{row.webhookPublicPrefix}</code>
                          </span>
                        ) : null}
                        <input
                          value={formName}
                          onChange={(e) => setFormName(e.target.value)}
                          required
                          className="inline-input"
                        />
                        {isAdmin && (
                          <input
                            type="number"
                            min="1"
                            className="inline-input narrow"
                            value={formUserId}
                            onChange={(e) => setFormUserId(e.target.value)}
                            title="ID utilisateur (vide = détacher)"
                            placeholder="User id"
                          />
                        )}
                        <button type="submit" className="btn small" disabled={saving}>
                          Enregistrer
                        </button>
                        <button type="button" className="btn secondary small" onClick={cancelEdit} disabled={saving}>
                          Annuler
                        </button>
                      </form>
                    </td>
                  </>
                ) : (
                  <>
                    <td>{row.id}</td>
                    <td>{row.name}</td>
                    <td>
                      <div className="org-webhook-prefix-cell">
                        <code className="mono small" title="Préfixe d’organisation (début des URL /webhook/form/…)">
                          {row.webhookPublicPrefix && row.webhookPublicPrefix !== ''
                            ? row.webhookPublicPrefix
                            : '—'}
                        </code>
                        {isAdmin ? (
                          <button
                            type="button"
                            className="btn secondary small org-regenerate-prefix-btn"
                            title="Attribuer un nouveau préfixe (les anciennes URL d’ingress cessent de fonctionner)"
                            disabled={regeneratingOrgId === row.id}
                            onClick={() => void regenerateWebhookPrefix(row)}
                          >
                            {regeneratingOrgId === row.id ? '…' : 'Régénérer'}
                          </button>
                        ) : null}
                      </div>
                    </td>
                    {isAdmin ? (
                      <>
                        <td className="small">{row.subscription?.planLabel ?? '—'}</td>
                        <td className="numeric small" title="Événements quota ce mois (compteur mensuel)">
                          {(row.adminListStats?.ingressCountCurrentMonth ?? 0).toLocaleString('fr-FR')}
                        </td>
                        <td className="numeric small" title="Consommé / plafond (forfait)">
                          {formatAdminQuotaCell(row.subscription)}
                        </td>
                        <td className="numeric">
                          <span
                            className="badge-count"
                            title={row.members?.map((m) => m.email).join(', ') ?? ''}
                          >
                            {row.memberCount ?? row.members?.length ?? 0}
                          </span>
                        </td>
                        <td className="numeric small">
                          {(row.adminListStats?.projectCount ?? 0).toLocaleString('fr-FR')}
                        </td>
                        <td className="numeric small">
                          {(row.subscription?.webhookCount ?? 0).toLocaleString('fr-FR')}
                        </td>
                      </>
                    ) : null}
                    {isAdmin ? (
                      <td className="actions">
                        <button type="button" className="btn secondary small" onClick={() => startEdit(row)}>
                          Modifier
                        </button>
                        <button type="button" className="btn danger small" onClick={() => void remove(row.id)}>
                          Supprimer
                        </button>
                      </td>
                    ) : (
                      <td>
                        <button type="button" className="btn secondary small" onClick={() => startEdit(row)}>
                          Modifier
                        </button>
                      </td>
                    )}
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      </div>
    </div>
  );
}
