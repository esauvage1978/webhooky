import { useCallback, useEffect, useMemo, useState } from 'react';
import { absoluteAppPath } from '../lib/paths.js';
import { apiJsonInit, parseJson } from '../lib/http.js';

/**
 * @param {{ user: object }} props
 */
export default function GoogleSearchConsolePanel({ user }) {
  const orgId = user.organization?.id;
  const isAdmin = user.roles?.includes?.('ROLE_ADMIN');
  const [status, setStatus] = useState(null);
  const [sites, setSites] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedSite, setSelectedSite] = useState('');
  const [savingSite, setSavingSite] = useState(false);
  const [notice, setNotice] = useState(null);
  const [projects, setProjects] = useState([]);
  const [projectId, setProjectId] = useState('');

  const projectApiBase = useMemo(() => {
    const pid = projectId ? Number(projectId) : 0;
    if (!pid || Number.isNaN(pid) || pid < 1) return '';
    return absoluteAppPath(`/api/webhook-projects/${pid}`);
  }, [projectId]);

  const selectedProject = useMemo(
    () => projects.find((p) => String(p.id) === String(projectId)),
    [projects, projectId],
  );
  const oauthReady =
    !!selectedProject &&
    String(selectedProject.googleOAuthClientId ?? '').trim() !== '' &&
    !!selectedProject.googleOAuthSecretConfigured;

  const loadProjects = useCallback(async () => {
    setError('');
    try {
      const r = await fetch('/api/webhook-projects', apiJsonInit({ method: 'GET' }));
      const data = await parseJson(r);
      if (!r.ok) {
        setProjects([]);
        return;
      }
      const items = Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : [];
      const filtered = orgId == null || isAdmin ? items : items.filter((p) => String(p.organizationId) === String(orgId));
      setProjects(filtered);
      const qp = new URLSearchParams(window.location.search);
      const fromQuery = qp.get('projectId');
      const preferred =
        fromQuery && filtered.some((p) => String(p.id) === String(fromQuery))
          ? String(fromQuery)
          : filtered[0]?.id != null
            ? String(filtered[0].id)
            : '';
      setProjectId((cur) => cur || preferred);
    } catch {
      setProjects([]);
    }
  }, [orgId, isAdmin]);

  const refresh = useCallback(async () => {
    if (!projectApiBase) return;
    setLoading(true);
    setError('');
    try {
      const r = await fetch(`${projectApiBase}/gsc`, apiJsonInit({ method: 'GET' }));
      const data = await parseJson(r);
      if (!r.ok) {
        setError((data && data.error) || 'Impossible de charger le statut GSC.');
        setStatus(null);
        return;
      }
      setStatus(data);
      if (data?.siteUrl) setSelectedSite(data.siteUrl);
    } catch {
      setError('Erreur réseau.');
    } finally {
      setLoading(false);
    }
  }, [projectApiBase]);

  const loadSites = useCallback(async () => {
    if (!projectApiBase) return;
    setError('');
    try {
      const r = await fetch(`${projectApiBase}/gsc/sites`, apiJsonInit({ method: 'GET' }));
      const data = await parseJson(r);
      if (!r.ok) {
        setError((data && data.error) || 'Liste des sites indisponible.');
        return;
      }
      setSites(Array.isArray(data?.items) ? data.items : []);
    } catch {
      setError('Erreur réseau (sites).');
    }
  }, [projectApiBase]);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  useEffect(() => {
    if (!projectApiBase) return;
    void refresh();
  }, [projectApiBase, refresh]);

  useEffect(() => {
    const p = new URLSearchParams(window.location.search);
    if (p.get('gsc') === 'connected') {
      setNotice({ type: 'ok', text: 'Google Search Console connecté. Sélectionnez la propriété à utiliser.' });
      void loadSites();
    }
  }, [loadSites]);

  const connectHref = useMemo(() => {
    const pid = projectId ? Number(projectId) : 0;
    if (!pid || Number.isNaN(pid) || pid < 1 || !oauthReady) return '#';
    return absoluteAppPath(`/auth/google?projectId=${encodeURIComponent(String(pid))}`);
  }, [projectId, oauthReady]);

  const saveSite = async () => {
    if (!projectApiBase || !selectedSite.trim()) return;
    setSavingSite(true);
    setError('');
    try {
      const r = await fetch(
        `${projectApiBase}/gsc/site`,
        apiJsonInit({
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ siteUrl: selectedSite.trim() }),
        }),
      );
      const data = await parseJson(r);
      if (!r.ok) {
        setError((data && data.error) || 'Enregistrement impossible.');
        return;
      }
      setNotice({ type: 'ok', text: 'Propriété GSC enregistrée pour cette organisation.' });
      await refresh();
    } catch {
      setError('Erreur réseau.');
    } finally {
      setSavingSite(false);
    }
  };

  const disconnect = async () => {
    if (!projectApiBase) return;
    if (!window.confirm('Déconnecter Google Search Console pour cette organisation ?')) return;
    setError('');
    try {
      const r = await fetch(`${projectApiBase}/gsc`, apiJsonInit({ method: 'DELETE' }));
      if (!r.ok && r.status !== 204) {
        const data = await parseJson(r);
        setError((data && data.error) || 'Échec de la déconnexion.');
        return;
      }
      setStatus({ connected: false });
      setSites([]);
      setSelectedSite('');
      setNotice({ type: 'ok', text: 'Intégration GSC supprimée.' });
    } catch {
      setError('Erreur réseau.');
    }
  };

  if (orgId == null) {
    return null;
  }

  return (
    <section className="integrations-block gsc-panel-wrap">
      <h3 className="integrations-block__title">
        <i className="fa-solid fa-chart-line" aria-hidden /> Google Search Console
      </h3>
      <p className="integrations-block__intro muted small">
        Les <strong>identifiants OAuth Google</strong> (Client ID + secret) se renseignent dans la fiche du projet (page{' '}
        <strong>Projets</strong>). Les jetons utilisateur sont chiffrés côté serveur. Les workflows <code>gsc_fetch</code>{' '}
        utilisent la propriété GSC choisie pour ce projet.
      </p>
      {!oauthReady && projectId && selectedProject ? (
        <div className="auth-notice err" role="status" style={{ maxWidth: '40rem' }}>
          Renseignez le Client ID et le secret Google pour ce projet dans{' '}
          <a href={absoluteAppPath(`/projets-workflows?editProject=${encodeURIComponent(String(projectId))}`)}>
            Projets → modifier ce projet
          </a>
          , puis revenez ici pour connecter Search Console.
        </div>
      ) : null}
      <label className="field" style={{ maxWidth: '28rem' }}>
        <span>Projet</span>
        <select value={projectId} onChange={(e) => setProjectId(e.target.value)} disabled={projects.length === 0}>
          {projects.length === 0 ? <option value="">Aucun projet</option> : null}
          {projects.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </label>
      <div className="gsc-panel-actions" style={{ marginBottom: '1rem', display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
        <a
          className={`btn btn-primary wp-proj-hero-btn${connectHref === '#' ? ' disabled' : ''}`}
          href={connectHref}
          aria-disabled={connectHref === '#'}
          onClick={(e) => {
            if (connectHref === '#') e.preventDefault();
          }}
          title={!oauthReady ? 'Configurez d’abord OAuth sur le projet' : undefined}
        >
          <i className="fa-brands fa-google" aria-hidden /> Connecter Google Search Console
        </a>
        {status?.connected ? (
          <button type="button" className="btn btn-outline-secondary wp-proj-hero-btn" onClick={() => void loadSites()}>
            <i className="fa-solid fa-rotate" aria-hidden /> Actualiser les sites
          </button>
        ) : null}
      </div>

      <div className="content-card gsc-panel-card">
        {notice ? (
          <div className={`auth-notice ${notice.type === 'ok' ? 'ok' : 'err'}`} role="status">
            {notice.text}
          </div>
        ) : null}
        {error ? (
          <div className="auth-notice err" role="alert">
            {error}
          </div>
        ) : null}
        {loading ? <p className="muted">Chargement…</p> : null}
        {!loading && status ? (
          <div className="gsc-status-grid">
            <div>
              <h3 className="integrations-block__title">Statut</h3>
              <p className="muted small">
                {status.connected ? (
                  <>
                    Connecté
                    {status.expiresAt ? (
                      <>
                        {' '}
                        — jeton jusqu’au <time dateTime={status.expiresAt}>{status.expiresAt}</time>
                      </>
                    ) : null}
                  </>
                ) : (
                  'Non connecté'
                )}
              </p>
              {status.connected && status.siteUrl ? (
                <p className="small">
                  Propriété active : <code>{status.siteUrl}</code>
                </p>
              ) : null}
            </div>
            {status.connected ? (
              <div className="gsc-site-select">
                <h3 className="integrations-block__title">Propriété (site) GSC</h3>
                <p className="muted small">
                  Choisissez l’URL exacte retournée par Google (ex. <code>https://www.example.com/</code> ou{' '}
                  <code>sc-domain:example.com</code>).
                </p>
                {sites.length > 0 ? (
                  <select
                    className="form-control"
                    value={selectedSite}
                    onChange={(e) => setSelectedSite(e.target.value)}
                    aria-label="Sélection de la propriété Search Console"
                  >
                    <option value="">— Choisir —</option>
                    {sites.map((s) => (
                      <option key={s.siteUrl} value={s.siteUrl}>
                        {s.siteUrl} {s.permissionLevel ? `(${s.permissionLevel})` : ''}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    className="form-control"
                    type="text"
                    placeholder="Collez l’URL de la propriété"
                    value={selectedSite}
                    onChange={(e) => setSelectedSite(e.target.value)}
                  />
                )}
                <div className="gsc-site-actions">
                  <button type="button" className="btn btn-primary" disabled={savingSite} onClick={() => void saveSite()}>
                    Enregistrer la propriété
                  </button>
                  <button type="button" className="btn btn-outline-danger" onClick={() => void disconnect()}>
                    Déconnecter
                  </button>
                </div>
              </div>
            ) : null}
          </div>
        ) : null}
        <p className="muted small" style={{ marginTop: '1rem' }}>
          Exemple de pipeline JSON : voir le fichier <code>workflows/seo_pipeline_example.json</code> à la racine du
          dossier <code>builder/</code>.
        </p>
      </div>
    </section>
  );
}
