import { useCallback, useEffect, useState } from 'react';
import ModalPortal from '../components/ModalPortal.jsx';
import { parseJson } from '../lib/http.js';

/**
 * @param {object[]} projects
 * @param {string} orgIdStr
 */
function projectsForOrg(projects, orgIdStr, isAdmin) {
  if (!isAdmin) return projects;
  return projects.filter((p) => String(p.organizationId) === String(orgIdStr));
}

export default function WebhookProjects({ user, onNavigate }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const [projects, setProjects] = useState([]);
  const [orgs, setOrgs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [newProjectName, setNewProjectName] = useState('');
  const [newProjectDesc, setNewProjectDesc] = useState('');
  const [newProjectGoogleClientId, setNewProjectGoogleClientId] = useState('');
  const [newProjectGoogleClientSecret, setNewProjectGoogleClientSecret] = useState('');
  const [newProjectOrgId, setNewProjectOrgId] = useState('');
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState('');
  /** @type {'create' | 'edit' | null} */
  const [modal, setModal] = useState(null);
  const [editingProject, setEditingProject] = useState(null);

  const setEditProjectInUrl = useCallback((projectId) => {
    const p = new URLSearchParams(window.location.search);
    if (projectId == null || projectId === '' || Number(projectId) < 1) {
      p.delete('editProject');
    } else {
      p.set('editProject', String(projectId));
    }
    const qs = p.toString();
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}`;
    window.history.replaceState({}, '', next);
  }, []);

  const loadProjects = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [rP, rO] = await Promise.all([
        fetch('/api/webhook-projects', { credentials: 'include' }),
        isAdmin ? fetch('/api/organizations', { credentials: 'include' }) : Promise.resolve(null),
      ]);
      const dP = await parseJson(rP);
      if (!rP.ok) {
        setError(dP?.error ?? 'Erreur chargement');
        setProjects([]);
        return;
      }
      setProjects(Array.isArray(dP) ? dP : []);
      if (isAdmin && rO) {
        const dO = await parseJson(rO);
        if (rO.ok && Array.isArray(dO)) setOrgs(dO);
      }
    } catch {
      setError('Erreur réseau');
      setProjects([]);
    } finally {
      setLoading(false);
    }
  }, [isAdmin]);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  useEffect(() => {
    if (isAdmin && orgs[0] && !newProjectOrgId) {
      setNewProjectOrgId(String(orgs[0].id));
    }
  }, [isAdmin, orgs, newProjectOrgId]);

  const closeModal = useCallback(() => {
    setModal(null);
    setEditingProject(null);
    setEditProjectInUrl(null);
  }, []);

  const openCreateModal = () => {
    setNewProjectName('');
    setNewProjectDesc('');
    setNewProjectGoogleClientId('');
    setNewProjectGoogleClientSecret('');
    if (isAdmin && orgs[0]) setNewProjectOrgId(String(orgs[0].id));
    setEditingProject(null);
    setModal('create');
    setMsg('');
  };

  const openEditModal = (p) => {
    setEditingProject({
      id: p.id,
      name: p.name,
      description: p.description ?? '',
      isDefault: !!p.isDefault,
      googleOAuthClientId: p.googleOAuthClientId ?? '',
      googleOAuthClientSecret: '',
      googleOAuthSecretConfigured: !!p.googleOAuthSecretConfigured,
    });
    setModal('edit');
    setMsg('');
    setEditProjectInUrl(p.id);
  };

  // Deep-link : /projets-workflows?editProject=123
  useEffect(() => {
    if (loading || modal != null) return;
    const raw = new URLSearchParams(window.location.search).get('editProject');
    if (!raw) return;
    const id = Number.parseInt(raw, 10);
    if (!Number.isFinite(id) || id < 1) return;
    const row = projects.find((p) => p.id === id);
    if (row) {
      openEditModal(row);
    }
  }, [loading, modal, projects]);

  const submitCreate = async (e) => {
    e.preventDefault();
    const name = newProjectName.trim();
    if (!name) {
      setMsg('Le nom du projet est obligatoire.');
      return;
    }
    setSaving(true);
    setMsg('');
    try {
      const body = {
        name,
        description: newProjectDesc.trim() ? newProjectDesc.trim() : null,
      };
      const gid = newProjectGoogleClientId.trim();
      const gsec = newProjectGoogleClientSecret.trim();
      if (gid) body.googleOAuthClientId = gid;
      if (gsec) body.googleOAuthClientSecret = gsec;
      if (isAdmin) {
        const oid = newProjectOrgId || (orgs[0]?.id != null ? String(orgs[0].id) : '');
        if (!oid) {
          setMsg('Choisissez une organisation.');
          setSaving(false);
          return;
        }
        body.organizationId = Number(oid);
      }
      const res = await fetch('/api/webhook-projects', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setMsg(data?.error ?? 'Erreur');
        return;
      }
      closeModal();
      setMsg('Projet créé.');
      await loadProjects();
    } catch {
      setMsg('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const saveEdited = async (e) => {
    if (e) e.preventDefault();
    if (!editingProject?.id) return;
    const name = editingProject.name?.trim();
    if (!name) {
      setMsg('Le nom du projet est obligatoire.');
      return;
    }
    setSaving(true);
    setMsg('');
    try {
      const body = {
        name,
        description: editingProject.description?.trim() ? editingProject.description.trim() : null,
        googleOAuthClientId: editingProject.googleOAuthClientId?.trim() ?? '',
      };
      if (editingProject.googleOAuthClientSecret?.trim()) {
        body.googleOAuthClientSecret = editingProject.googleOAuthClientSecret.trim();
      }
      const res = await fetch(`/api/webhook-projects/${editingProject.id}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setMsg(data?.error ?? 'Erreur');
        return;
      }
      closeModal();
      setMsg('Projet mis à jour.');
      await loadProjects();
    } catch {
      setMsg('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  useEffect(() => {
    if (modal == null) return;
    const onKey = (e) => {
      if (e.key === 'Escape' && !saving) closeModal();
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [modal, saving, closeModal]);

  const removeById = async (id) => {
    if (!window.confirm('Supprimer ce projet ? Les workflows rattachés seront replacés dans le projet « Général ».')) {
      return;
    }
    setSaving(true);
    setMsg('');
    try {
      const res = await fetch(`/api/webhook-projects/${id}`, { method: 'DELETE', credentials: 'include' });
      if (!res.ok) {
        const data = await parseJson(res);
        setMsg(data?.error ?? 'Erreur');
        return;
      }
      closeModal();
      setMsg('Projet supprimé.');
      await loadProjects();
    } catch {
      setMsg('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  const grouped = isAdmin
    ? orgs.map((o) => ({
        org: o,
        items: projectsForOrg(projects, String(o.id), true),
      }))
    : [{ org: user.organization, items: projects }];

  return (
    <div className="users-shell org-section webhook-projects-page">
      <header className="users-hero users-hero--minimal">
        <h1 className="users-hero-title wp-proj-page-title">
          <i className="fa-solid fa-folder-open" aria-hidden />
          <span>Projets</span>
        </h1>
        <div className="users-hero-actions wp-projects-hero-actions">
          <button
            type="button"
            className="btn secondary small wp-proj-hero-btn"
            onClick={() => onNavigate?.('formWebhooks')}
          >
            <i className="fa-solid fa-diagram-project" aria-hidden />
            <span>Vers les workflows</span>
          </button>
          <button type="button" className="fw-btn-primary wp-proj-hero-btn" onClick={openCreateModal} disabled={loading}>
            <i className="fa-solid fa-folder-plus" aria-hidden />
            <span>Nouveau projet</span>
          </button>
        </div>
      </header>

      <div className="content-card">
        {error ? <p className="error">{error}</p> : null}
        {msg && modal === null ? (
          <p className={msg.includes('Erreur') ? 'error small' : 'muted small'}>{msg}</p>
        ) : null}

        {loading ? (
          <p className="muted">Chargement…</p>
        ) : (
          <>
            {grouped.map((block, blockIdx) => {
              if (!block.org) return null;
              const list = block.items;
              return (
                <section
                  key={block.org.id ?? 'mine'}
                  className={`integrations-block${blockIdx > 0 ? ' wp-projects-org-block' : ''}`}
                >
                  {isAdmin ? (
                    <h3 className="integrations-block__title wp-proj-org-title">
                      <i className="fa-solid fa-building" aria-hidden />
                      <span>
                        {block.org.name} <small className="dash-id">#{block.org.id}</small>
                      </span>
                    </h3>
                  ) : null}
                  {list.length === 0 ? (
                    <p className="integrations-block__intro muted small">
                      Aucun projet listé (le dossier Général est créé automatiquement avec l’organisation).
                    </p>
                  ) : (
                    <div className="integrations-block__table-wrap org-table-wrap">
                      <table className="org-table mailjet-table">
                        <thead>
                          <tr>
                            <th className="wp-proj-th-icon">
                              <i className="fa-solid fa-tag" aria-hidden />
                              <span>Nom</span>
                            </th>
                            <th className="wp-proj-th-icon">
                              <i className="fa-solid fa-align-left" aria-hidden />
                              <span>Description</span>
                            </th>
                            <th className="wp-proj-th-icon wp-proj-th-center">
                              <i className="fa-solid fa-gears" aria-hidden />
                              <span>Workflows</span>
                            </th>
                            <th className="actions org-table-th-actions" aria-label="Actions sur le projet" />
                          </tr>
                        </thead>
                        <tbody>
                          {list.map((p) => (
                            <tr key={p.id}>
                              <td>
                                <button
                                  type="button"
                                  className="btn secondary small"
                                  style={{ padding: '0.3rem 0.55rem', marginTop: 0 }}
                                  disabled={saving}
                                  onClick={() => openEditModal(p)}
                                  title="Éditer ce projet"
                                >
                                  <i className="fa-solid fa-pen-to-square" aria-hidden /> <strong>{p.name}</strong>
                                </button>
                                {p.isDefault ? (
                                  <span className="muted small" style={{ marginLeft: '0.35rem' }}>
                                    (par défaut)
                                  </span>
                                ) : null}
                              </td>
                              <td className="muted small">{p.description ?? '—'}</td>
                              <td className="wp-proj-count-cell">
                                {typeof p.webhookCount === 'number' ? (
                                  <span className="wp-proj-count-inner">
                                    <i className="fa-solid fa-sitemap" aria-hidden />
                                    <span>{p.webhookCount}</span>
                                  </span>
                                ) : (
                                  '—'
                                )}
                              </td>
                              <td className="actions">
                                <div className="org-table-actions-inner org-table-actions-inner--proj">
                                  <button
                                    type="button"
                                    className="btn secondary small org-table-action-btn"
                                    disabled={saving}
                                    onClick={() => openEditModal(p)}
                                  >
                                    <i className="fa-solid fa-pen-to-square" aria-hidden />
                                    <span>Modifier</span>
                                  </button>
                                  <button
                                    type="button"
                                    className="btn danger small org-table-action-btn"
                                    disabled={saving || p.isDefault}
                                    title={p.isDefault ? 'Le projet Général ne peut pas être supprimé' : 'Supprimer'}
                                    onClick={() => void removeById(p.id)}
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
                    </div>
                  )}
                </section>
              );
            })}
          </>
        )}
      </div>

      {modal === 'create' ? (
        <ModalPortal>
        <div className="sc-modal-backdrop" role="presentation">
          <div
            className="sc-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="wh-proj-create-title"
          >
            <div className="sc-modal-head">
              <h4 id="wh-proj-create-title" className="wp-proj-modal-title">
                <i className="fa-solid fa-folder-plus" aria-hidden />
                <span>Nouveau projet</span>
              </h4>
              <button type="button" className="sc-modal-close" aria-label="Fermer" onClick={closeModal} disabled={saving}>
                ×
              </button>
            </div>
            <form className="org-form mailjet-form sc-modal-form" onSubmit={(e) => void submitCreate(e)}>
              <p className="muted small" style={{ marginTop: 0 }}>
                Le nom est unique au sein de l’organisation.
              </p>
              {msg ? <p className="error small">{msg}</p> : null}
              {isAdmin ? (
                <label className="field">
                  <span>Organisation</span>
                  <select value={newProjectOrgId} onChange={(e) => setNewProjectOrgId(e.target.value)} required>
                    {orgs.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}
              <label className="field">
                <span>Nom</span>
                <input
                  value={newProjectName}
                  onChange={(e) => setNewProjectName(e.target.value)}
                  placeholder="Ex. Site vitrine, Intranet…"
                  required
                  autoFocus
                />
              </label>
              <label className="field">
                <span>Description (optionnel)</span>
                <input value={newProjectDesc} onChange={(e) => setNewProjectDesc(e.target.value)} />
              </label>
              <details className="wp-proj-oauth-details">
                <summary className="muted small" style={{ cursor: 'pointer' }}>
                  OAuth Google Search Console (optionnel à la création)
                </summary>
                <p className="muted small" style={{ marginTop: '0.5rem' }}>
                  Identifiants du client OAuth de la console Google Cloud (type « application Web »), avec l’URI de
                  redirection de cette app. Vous pourrez les modifier plus tard dans ce même écran.
                </p>
                <label className="field">
                  <span>Client ID Google</span>
                  <input
                    value={newProjectGoogleClientId}
                    onChange={(e) => setNewProjectGoogleClientId(e.target.value)}
                    autoComplete="off"
                  />
                </label>
                <label className="field">
                  <span>Client secret Google</span>
                  <input
                    type="password"
                    value={newProjectGoogleClientSecret}
                    onChange={(e) => setNewProjectGoogleClientSecret(e.target.value)}
                    autoComplete="new-password"
                  />
                </label>
              </details>
              <div className="sc-modal-actions">
                <button type="submit" className="btn wp-proj-modal-btn" disabled={saving}>
                  {saving ? (
                    <>
                      <i className="fa-solid fa-spinner fa-spin" aria-hidden />
                      <span>Enregistrement…</span>
                    </>
                  ) : (
                    <>
                      <i className="fa-solid fa-check" aria-hidden />
                      <span>Créer le projet</span>
                    </>
                  )}
                </button>
                <button type="button" className="btn secondary wp-proj-modal-btn" onClick={closeModal} disabled={saving}>
                  <i className="fa-solid fa-xmark" aria-hidden />
                  <span>Annuler</span>
                </button>
              </div>
            </form>
          </div>
        </div>
        </ModalPortal>
      ) : null}

      {modal === 'edit' && editingProject ? (
        <ModalPortal>
        <div className="sc-modal-backdrop" role="presentation">
          <div
            className="sc-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="wh-proj-edit-title"
          >
            <div className="sc-modal-head">
              <h4 id="wh-proj-edit-title" className="wp-proj-modal-title">
                <i className="fa-solid fa-pen-to-square" aria-hidden />
                <span>Modifier le projet</span>
              </h4>
              <button type="button" className="sc-modal-close" aria-label="Fermer" onClick={closeModal} disabled={saving}>
                ×
              </button>
            </div>
            <form className="org-form mailjet-form sc-modal-form" onSubmit={(e) => void saveEdited(e)}>
              {editingProject.isDefault ? (
                <p className="muted small" style={{ marginTop: 0 }}>
                  Le nom du projet par défaut <strong>Général</strong> ne peut pas être modifié. Vous pouvez encore ajuster
                  la description.
                </p>
              ) : (
                <p className="muted small" style={{ marginTop: 0 }}>
                  Le nom reste unique dans l’organisation.
                </p>
              )}
              {msg ? <p className="error small">{msg}</p> : null}
              <label className="field">
                <span>Nom</span>
                <input
                  value={editingProject.name}
                  onChange={(e) => setEditingProject((ep) => ({ ...ep, name: e.target.value }))}
                  disabled={editingProject.isDefault}
                  autoFocus={!editingProject.isDefault}
                />
              </label>
              <label className="field">
                <span>Description</span>
                <input
                  value={editingProject.description ?? ''}
                  onChange={(e) => setEditingProject((ep) => ({ ...ep, description: e.target.value }))}
                  autoFocus={editingProject.isDefault}
                />
              </label>
              <div className="integrations-block" style={{ marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid var(--border, #e5e7eb)' }}>
                <h5 className="integrations-block__title" style={{ fontSize: '0.95rem', marginBottom: '0.35rem' }}>
                  OAuth Google Search Console
                </h5>
                <p className="muted small" style={{ marginTop: 0 }}>
                  Ces champs permettent à vos utilisateurs de connecter Search Console pour ce projet. Le secret est stocké
                  chiffré et n’est jamais réaffiché ; laissez-le vide pour conserver le secret actuel.
                </p>
                <label className="field">
                  <span>Client ID Google</span>
                  <input
                    value={editingProject.googleOAuthClientId ?? ''}
                    onChange={(e) => setEditingProject((ep) => ({ ...ep, googleOAuthClientId: e.target.value }))}
                    autoComplete="off"
                  />
                </label>
                <label className="field">
                  <span>Client secret Google</span>
                  <input
                    type="password"
                    value={editingProject.googleOAuthClientSecret ?? ''}
                    onChange={(e) => setEditingProject((ep) => ({ ...ep, googleOAuthClientSecret: e.target.value }))}
                    placeholder={
                      editingProject.googleOAuthSecretConfigured ? '•••• laisser vide pour ne pas changer' : ''
                    }
                    autoComplete="new-password"
                  />
                </label>
              </div>
              <div className="sc-modal-actions">
                <button type="submit" className="btn wp-proj-modal-btn" disabled={saving}>
                  {saving ? (
                    <>
                      <i className="fa-solid fa-spinner fa-spin" aria-hidden />
                      <span>Enregistrement…</span>
                    </>
                  ) : (
                    <>
                      <i className="fa-solid fa-floppy-disk" aria-hidden />
                      <span>Enregistrer</span>
                    </>
                  )}
                </button>
                <button type="button" className="btn secondary wp-proj-modal-btn" onClick={closeModal} disabled={saving}>
                  <i className="fa-solid fa-xmark" aria-hidden />
                  <span>Annuler</span>
                </button>
              </div>
            </form>
          </div>
        </div>
        </ModalPortal>
      ) : null}
    </div>
  );
}
