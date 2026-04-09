import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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

function roleLabel(roles) {
  if (roles.includes('ROLE_ADMIN')) return 'Administrateur';
  if (roles.includes('ROLE_MANAGER')) return 'Gestionnaire';
  return 'Utilisateur';
}

function formatDateTime(iso) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return '—';
  }
}

export default function Users({ user }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const isManager = !isAdmin && user.roles.includes('ROLE_MANAGER');
  const [inviteOpen, setInviteOpen] = useState(false);
  const [moreMenuOpen, setMoreMenuOpen] = useState(false);
  const moreMenuRef = useRef(null);
  const [accounts, setAccounts] = useState([]);
  const [organizations, setOrganizations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteOrgId, setInviteOrgId] = useState('');
  const [saving, setSaving] = useState(false);

  const [listPage, setListPage] = useState(1);
  const [listPerPage, setListPerPage] = useState(20);
  const [searchInput, setSearchInput] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [filterRole, setFilterRole] = useState('');
  const [filterOrgId, setFilterOrgId] = useState('');
  const [listTotal, setListTotal] = useState(0);
  const [listTotalPages, setListTotalPages] = useState(1);

  const journalHref = absoluteAppPath('/users/journal');

  const inviteOrgIdValid = useMemo(() => {
    if (!isAdmin) return true;
    return inviteOrgId.trim() !== '';
  }, [isAdmin, inviteOrgId]);

  useEffect(() => {
    const t = window.setTimeout(() => {
      setDebouncedSearch(searchInput.trim());
      setListPage(1);
    }, 350);
    return () => window.clearTimeout(t);
  }, [searchInput]);

  const refreshAccounts = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams();
      params.set('page', String(listPage));
      params.set('perPage', String(listPerPage));
      if (debouncedSearch) params.set('search', debouncedSearch);
      if (filterRole) params.set('role', filterRole);
      if (isAdmin && filterOrgId) params.set('organizationId', filterOrgId);
      const res = await fetch(`/api/users?${params.toString()}`, { credentials: 'include' });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Chargement impossible');
        setAccounts([]);
        setListTotal(0);
        setListTotalPages(1);
        return;
      }
      if (data && Array.isArray(data.items)) {
        setAccounts(data.items);
        setListTotal(typeof data.total === 'number' ? data.total : data.items.length);
        setListTotalPages(typeof data.totalPages === 'number' ? Math.max(1, data.totalPages) : 1);
      } else {
        setAccounts([]);
        setListTotal(0);
        setListTotalPages(1);
      }
    } catch {
      setError('Erreur réseau');
      setAccounts([]);
      setListTotal(0);
      setListTotalPages(1);
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, filterOrgId, filterRole, isAdmin, listPage, listPerPage]);

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
    void refreshOrgs();
  }, [refreshOrgs]);

  useEffect(() => {
    void refreshAccounts();
  }, [refreshAccounts]);

  useEffect(() => {
    if (isManager && filterRole === 'admin') {
      setFilterRole('');
      setListPage(1);
    }
  }, [isManager, filterRole]);

  useEffect(() => {
    if (!moreMenuOpen) return;
    const onDoc = (e) => {
      if (moreMenuRef.current && !moreMenuRef.current.contains(e.target)) {
        setMoreMenuOpen(false);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setMoreMenuOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [moreMenuOpen]);

  useEffect(() => {
    if (!inviteOpen) return;
    const onKey = (e) => {
      if (e.key === 'Escape') setInviteOpen(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [inviteOpen]);

  useEffect(() => {
    if (isAdmin && organizations[0] && !inviteOrgId) {
      setInviteOrgId(String(organizations[0].id));
    }
  }, [isAdmin, organizations, inviteOrgId]);

  const closeInviteModal = () => {
    setInviteOpen(false);
    setError('');
  };

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
      closeInviteModal();
      await refreshAccounts();
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

  return (
    <div className="users-shell">
      <header className="users-hero users-hero--minimal">
        <h1 className="users-hero-title">
          <i className="fa-solid fa-users" aria-hidden />
          <span>Utilisateurs</span>
        </h1>
        <div className="users-hero-actions">
          <button
            type="button"
            className="users-btn-invite"
            onClick={() => {
              setError('');
              setInviteOpen(true);
            }}
          >
            <i className="fa-solid fa-user-plus" aria-hidden />
            <span>Inviter un utilisateur</span>
          </button>
          <div className="users-dropdown-wrap" ref={moreMenuRef}>
            <button
              type="button"
              className={`users-btn-more${moreMenuOpen ? ' is-open' : ''}`}
              aria-expanded={moreMenuOpen}
              aria-haspopup="menu"
              aria-label="Autres actions"
              onClick={() => setMoreMenuOpen((o) => !o)}
            >
              <i className="fa-solid fa-ellipsis-vertical" aria-hidden />
            </button>
            {moreMenuOpen ? (
              <ul className="users-dropdown-menu" role="menu">
                <li role="none">
                  <a
                    href={journalHref}
                    target="_blank"
                    rel="noopener noreferrer"
                    role="menuitem"
                    className="users-dropdown-link"
                    onClick={() => setMoreMenuOpen(false)}
                  >
                    <i className="fa-solid fa-clock-rotate-left" aria-hidden />
                    <span>Journal des actions</span>
                    <i className="fa-solid fa-arrow-up-right-from-square users-dropdown-external" aria-hidden />
                  </a>
                </li>
              </ul>
            ) : null}
          </div>
        </div>
      </header>

      <div className="content-card">
        {error && !inviteOpen ? <p className="error users-page-error">{error}</p> : null}

        <div className="users-filters-minimal">
          <label className="users-filter-min">
            <span className="users-filter-min-label muted">Recherche</span>
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="E-mail ou nom"
              autoComplete="off"
            />
          </label>
          <label className="users-filter-min">
            <span className="users-filter-min-label muted">Rôle</span>
            <select
              value={filterRole}
              onChange={(e) => {
                setFilterRole(e.target.value);
                setListPage(1);
              }}
            >
              <option value="">Tous</option>
              {isAdmin ? <option value="admin">Administrateur</option> : null}
              <option value="manager">Gestionnaire</option>
              <option value="member">Utilisateur</option>
            </select>
          </label>
          {isAdmin ? (
            <label className="users-filter-min">
              <span className="users-filter-min-label muted">Organisation</span>
              <select
                value={filterOrgId}
                onChange={(e) => {
                  setFilterOrgId(e.target.value);
                  setListPage(1);
                }}
              >
                <option value="">Toutes</option>
                {organizations.map((o) => (
                  <option key={o.id} value={String(o.id)}>
                    {o.name}
                  </option>
                ))}
              </select>
            </label>
          ) : null}
          <label className="users-filter-min users-filter-min--narrow">
            <span className="users-filter-min-label muted">Par page</span>
            <select
              value={String(listPerPage)}
              onChange={(e) => {
                setListPerPage(Number(e.target.value) || 20);
                setListPage(1);
              }}
            >
              <option value="10">10</option>
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>
        </div>

        <div className="users-pagination-minimal">
          <span className="muted small">{loading ? 'Chargement…' : `${listTotal} compte${listTotal > 1 ? 's' : ''}`}</span>
          <div className="users-pagination-minimal-controls">
            <button
              type="button"
              className="users-pill-btn"
              disabled={loading || listPage <= 1}
              onClick={() => setListPage((p) => Math.max(1, p - 1))}
            >
              Précédent
            </button>
            <span className="muted small">
              {listPage} / {listTotalPages}
            </span>
            <button
              type="button"
              className="users-pill-btn"
              disabled={loading || listPage >= listTotalPages}
              onClick={() => setListPage((p) => p + 1)}
            >
              Suivant
            </button>
          </div>
        </div>

        <div className="org-table-wrap">
          <table className="org-table">
            <thead>
              <tr>
                <th>E-mail</th>
                <th>Rôle</th>
                {isAdmin ? <th>Organisation</th> : null}
                <th>Création</th>
                <th>Dernière connexion</th>
                <th>Statut</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {!loading && accounts.length === 0 ? (
                <tr>
                  <td colSpan={isAdmin ? 7 : 6} className="muted users-table-empty">
                    Aucun utilisateur ne correspond aux critères.
                  </td>
                </tr>
              ) : null}
              {accounts.map((row) => (
                <tr key={row.id}>
                  <td>{row.email}</td>
                  <td>{roleLabel(row.roles ?? [])}</td>
                  {isAdmin ? <td>{row.organization ? row.organization.name : '—'}</td> : null}
                  <td className="muted nowrap">{formatDateTime(row.createdAt)}</td>
                  <td className="muted nowrap">{formatDateTime(row.lastLoginAt)}</td>
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
                          <button
                            type="button"
                            className="users-pill-btn users-pill-btn--danger-outline"
                            onClick={() => void setEnabled(row, false)}
                          >
                            Bloquer
                          </button>
                        ) : (
                          <button type="button" className="users-pill-btn" onClick={() => void setEnabled(row, true)}>
                            Débloquer
                          </button>
                        )}
                        <button
                          type="button"
                          className="users-pill-btn users-pill-btn--danger"
                          onClick={() => void removeUser(row)}
                        >
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
      </div>

      {inviteOpen ? (
        <div
          className="modal-backdrop"
          role="presentation"
          onClick={closeInviteModal}
          onKeyDown={(e) => e.key === 'Escape' && closeInviteModal()}
        >
          <div
            className="modal-panel users-invite-panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="users-invite-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="modal-panel-header">
              <h3 id="users-invite-title" className="users-invite-modal-title">
                <i className="fa-solid fa-user-plus" aria-hidden />
                <span>Inviter un utilisateur</span>
              </h3>
              <button type="button" className="modal-close" aria-label="Fermer" onClick={closeInviteModal}>
                ×
              </button>
            </div>
            <p className="muted small">
              L’invité recevra un e-mail avec un lien pour définir son mot de passe. Rôle « Utilisateur ».
            </p>
            {error && inviteOpen ? <p className="error">{error}</p> : null}
            <form className="org-form users-invite-form" onSubmit={(e) => void submitInvite(e)}>
              <div className="users-invite-row users-invite-row--modal">
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
                ) : (
                  <div className="field users-org-readonly">
                    <span>Organisation</span>
                    <p className="users-org-readonly-value">{user.organization?.name ?? '—'}</p>
                  </div>
                )}
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
              </div>
              <div className="modal-actions">
                <button type="button" className="btn secondary" onClick={closeInviteModal}>
                  Annuler
                </button>
                <button type="submit" className="btn" disabled={saving || !inviteOrgIdValid}>
                  {saving ? '…' : 'Envoyer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
