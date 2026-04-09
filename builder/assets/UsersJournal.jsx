import { useCallback, useEffect, useState } from 'react';
import { absoluteAppPath } from './appPaths.js';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

const ACTION_LABELS = {
  'user.invited': 'Invitation envoyée',
  'user.invite_completed': 'Invitation finalisée',
  'user.blocked': 'Accès désactivé',
  'user.unblocked': 'Accès réactivé',
  'user.deleted': 'Compte supprimé',
};

export default function UsersJournal({ user }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [auditLogs, setAuditLogs] = useState([]);
  const [loading, setLoading] = useState(true);

  const refreshAudit = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/users/audit-logs', { credentials: 'include' });
      const data = await parseJson(res);
      if (res.ok && Array.isArray(data)) setAuditLogs(data);
      else setAuditLogs([]);
    } catch {
      setAuditLogs([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refreshAudit();
  }, [refreshAudit]);

  const listUrl = absoluteAppPath('/users');

  return (
    <div className="users-shell users-shell--journal">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-clock-rotate-left" aria-hidden />
            <span>Journal des actions</span>
          </h1>
          <p className="users-hero-sub muted">
            {isAdmin
              ? 'Toutes les actions sur les comptes (toutes organisations).'
              : 'Actions enregistrées pour les utilisateurs de votre organisation.'}
          </p>
        </div>
        <a href={listUrl} className="users-hero-link">
          <i className="fa-solid fa-arrow-left" aria-hidden />
          <span>Utilisateurs</span>
        </a>
      </header>

      <div className="content-card">
        {loading ? (
          <p className="muted small">Chargement…</p>
        ) : (
          <div className="org-table-wrap">
            <table className="org-table">
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
                {auditLogs.length === 0 ? (
                  <tr>
                    <td colSpan={isAdmin ? 5 : 4} className="muted users-table-empty">
                      Aucune entrée pour l’instant.
                    </td>
                  </tr>
                ) : null}
                {auditLogs.map((log) => (
                  <tr key={log.id}>
                    <td className="muted nowrap">{new Date(log.occurredAt).toLocaleString('fr-FR')}</td>
                    <td>{ACTION_LABELS[log.action] ?? log.action}</td>
                    <td>{log.actor ? log.actor.email : '—'}</td>
                    <td>{log.targetEmail ?? '—'}</td>
                    {isAdmin ? <td>{log.organization ? log.organization.name : '—'}</td> : null}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
