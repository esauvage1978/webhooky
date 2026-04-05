import { useCallback, useEffect, useMemo, useState } from 'react';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function roleLabel(roles) {
  if (roles.includes('ROLE_ADMIN')) return 'Administrateur';
  if (roles.includes('ROLE_MANAGER')) return 'Gestionnaire';
  return 'Utilisateur';
}

const ACTION_LABELS = {
  'user.invited': 'Invitation envoyée',
  'user.invite_completed': 'Invitation finalisée',
  'user.blocked': 'Accès désactivé',
  'user.unblocked': 'Accès réactivé',
  'user.deleted': 'Compte supprimé',
};

export default function Users({ user }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [accounts, setAccounts] = useState([]);
  const [auditLogs, setAuditLogs] = useState([]);
  const [organizations, setOrganizations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteOrgId, setInviteOrgId] = useState('');
  const [saving, setSaving] = useState(false);

  const inviteOrgIdValid = useMemo(() => {
    if (!isAdmin) return true;
    return inviteOrgId.trim() !== '';
  }, [isAdmin, inviteOrgId]);

  const refreshAccounts = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch('/api/users', { credentials: 'include' });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Chargement impossible');
        setAccounts([]);
        return;
      }
      setAccounts(Array.isArray(data) ? data : []);
    } catch {
      setError('Erreur réseau');
      setAccounts([]);
    } finally {
      setLoading(false);
    }
  }, []);

  const refreshAudit = useCallback(async () => {
    try {
      const res = await fetch('/api/users/audit-logs', { credentials: 'include' });
      const data = await parseJson(res);
      if (res.ok && Array.isArray(data)) setAuditLogs(data);
    } catch {
      /* ignore */
    }
  }, []);

  const refreshOrgs = useCallback(async () => {
    if (!isAdmin) return;
    try {
      const res = await fetch('/api/organizations', { credentials: 'include' });
      const data = await parseJson(res);
      if (res.ok && Array.isArray(data)) setOrganizations(data);
    } catch {
      /* ignore */
    }
  }, [isAdmin]);

  useEffect(() => {
    void refreshAccounts();
    void refreshAudit();
    void refreshOrgs();
  }, [refreshAccounts, refreshAudit, refreshOrgs]);

  const submitInvite = async (e) => {
    e.preventDefault();
    if (!inviteOrgIdValid) {
      setError('Choisissez une organisation.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const body = { email: inviteEmail.trim() };
      if (isAdmin) body.organizationId = Number(inviteOrgId);
      const res = await fetch('/api/users', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') {
          setError(Object.values(data.fields).join(' ') || data.error);
        } else {
          setError(data?.error ?? 'Invitation impossible');
        }
        return;
      }
      setInviteEmail('');
      await refreshAccounts();
      await refreshAudit();
    } catch {
      setError('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const setEnabled = async (row, enabled) => {
    if (!window.confirm(enabled ? 'Réactiver l’accès pour cet utilisateur ?' : 'Désactiver l’accès pour cet utilisateur ?')) return;
    setError('');
    try {
      const res = await fetch(`/api/users/${row.id}`, {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ accountEnabled: enabled }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Mise à jour impossible');
        return;
      }
      await refreshAccounts();
      await refreshAudit();
    } catch {
      setError('Erreur réseau');
    }
  };

  const removeUser = async (row) => {
    if (!window.confirm(`Supprimer définitivement le compte ${row.email} ?`)) return;
    setError('');
    try {
      const res = await fetch(`/api/users/${row.id}`, {
        method: 'DELETE',
        credentials: 'include',
      });
      if (!res.ok && res.status !== 204) {
        const data = await parseJson(res);
        setError(data?.error ?? 'Suppression impossible');
        return;
      }
      await refreshAccounts();
      await refreshAudit();
    } catch {
      setError('Erreur réseau');
    }
  };

  const canManageRow = (row) => {
    if (row.id === user.id) return false;
    if (isAdmin) return true;
    if (!user.roles.includes('ROLE_MANAGER')) return false;
    const isMember = !row.roles.includes('ROLE_ADMIN') && !row.roles.includes('ROLE_MANAGER');
    if (!isMember) return false;
    if (!user.organization || !row.organization) return false;
    return user.organization.id === row.organization.id;
  };

  if (loading) {
    return <p className="muted">Utilisateurs…</p>;
  }

  return (
    <section className="org-section">
      <h2>Utilisateurs</h2>
      <p className="muted">
        En tant que {isAdmin ? 'administrateur' : 'gestionnaire'}, vous pouvez inviter des utilisateurs, désactiver leur accès
        ou supprimer leur compte. Chaque action est enregistrée.
      </p>
      {error ? <p className="error">{error}</p> : null}

      <form className="org-form users-invite-form" onSubmit={(e) => void submitInvite(e)}>
        <h3>Inviter un utilisateur</h3>
        <p className="muted small">
          L’invité recevra un e-mail avec un lien pour définir son mot de passe. Il aura le rôle « Utilisateur ».
        </p>
        <div className="users-invite-row">
          {isAdmin ? (
            <label className="field">
              <span>Organisation</span>
              <select value={inviteOrgId} onChange={(e) => setInviteOrgId(e.target.value)} required>
                <option value="">— Choisir —</option>
                {organizations.map((o) => (
                  <option key={o.id} value={String(o.id)}>
                    {o.name} (#{o.id})
                  </option>
                ))}
              </select>
            </label>
          ) : null}
          <label className="field">
            <span>E-mail</span>
            <input
              type="email"
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
              required
              autoComplete="off"
            />
          </label>
          <div className="users-invite-actions">
            <button type="submit" className="btn" disabled={saving || !inviteOrgIdValid}>
              {saving ? '…' : 'Envoyer l’invitation'}
            </button>
          </div>
        </div>
      </form>

      <div className="org-table-wrap">
        <table className="org-table users-table">
          <thead>
            <tr>
              <th>E-mail</th>
              <th>Rôle</th>
              {isAdmin ? <th>Organisation</th> : null}
              <th>Statut</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {accounts.map((row) => (
              <tr key={row.id}>
                <td>{row.email}</td>
                <td>{roleLabel(row.roles ?? [])}</td>
                {isAdmin ? <td>{row.organization ? row.organization.name : '—'}</td> : null}
                <td>
                  {!row.accountEnabled ? (
                    <span className="badge danger">Désactivé</span>
                  ) : row.invitePending ? (
                    <span className="badge warn">Invitation en attente</span>
                  ) : (
                    <span className="badge ok">Actif</span>
                  )}
                </td>
                <td className="actions">
                  {canManageRow(row) ? (
                    <>
                      {row.accountEnabled ? (
                        <button type="button" className="btn secondary small" onClick={() => void setEnabled(row, false)}>
                          Bloquer
                        </button>
                      ) : (
                        <button type="button" className="btn secondary small" onClick={() => void setEnabled(row, true)}>
                          Débloquer
                        </button>
                      )}
                      <button type="button" className="btn danger small" onClick={() => void removeUser(row)}>
                        Supprimer
                      </button>
                    </>
                  ) : (
                    <span className="muted">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="users-audit-section">
        <h3>Journal des actions</h3>
        <div className="org-table-wrap">
          <table className="org-table users-audit-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Action</th>
                <th>Acteur</th>
                <th>Cible</th>
                {isAdmin ? <th>Organisation</th> : null}
              </tr>
            </thead>
            <tbody>
              {auditLogs.map((log) => (
                <tr key={log.id}>
                  <td className="muted nowrap">{new Date(log.occurredAt).toLocaleString()}</td>
                  <td>{ACTION_LABELS[log.action] ?? log.action}</td>
                  <td>{log.actor ? log.actor.email : '—'}</td>
                  <td>{log.targetEmail ?? '—'}</td>
                  {isAdmin ? <td>{log.organization ? log.organization.name : '—'}</td> : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}
