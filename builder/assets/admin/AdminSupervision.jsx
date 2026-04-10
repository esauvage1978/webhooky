import { useCallback, useEffect, useState } from 'react';
import ErrorAlert from '../components/ui/ErrorAlert.jsx';
import Tabs from '../components/ui/Tabs.jsx';
import { apiJsonInit, parseJson } from '../lib/http.js';

const SUPERVISION_TABS = [
  { id: 'errors', label: 'Erreurs applicatives' },
  { id: 'resources', label: 'Actions ressources' },
  { id: 'users', label: 'Actions comptes' },
  { id: 'options', label: 'Options plateforme' },
];

const emptyPlatformOptionForm = () => ({
  id: null,
  optionName: '',
  optionValue: '',
  domain: '',
  category: '',
  comment: '',
});

const USER_ACTION_LABELS = {
  'user.invited': 'Invitation envoyée',
  'user.invite_completed': 'Invitation finalisée',
  'user.blocked': 'Accès désactivé',
  'user.unblocked': 'Accès réactivé',
  'user.deleted': 'Compte supprimé',
};

const RESOURCE_ACTION_LABELS = {
  created: 'Création',
  updated: 'Modification',
  deleted: 'Suppression',
};

const RESOURCE_TYPE_LABELS = {
  form_webhook: 'Workflow',
  service_connection: 'Connecteur',
};

function levelBadgeClass(level) {
  if (level === 'critical') return 'danger';
  if (level === 'warning') return 'warn';
  return 'danger';
}

function sourceLabel(source) {
  if (source === 'handled') return 'Attrapée (try/catch)';
  return 'Exception';
}

export default function AdminSupervision() {
  const [tab, setTab] = useState('errors');
  const [errList, setErrList] = useState({ items: [], total: 0, page: 1, perPage: 50, loading: true, error: '' });
  const [errDetail, setErrDetail] = useState({ loading: false, data: null, error: '' });
  const [selectedErrId, setSelectedErrId] = useState(null);
  const [resAudit, setResAudit] = useState({
    items: [],
    total: 0,
    page: 1,
    perPage: 50,
    loading: true,
    error: '',
  });
  const [resFilters, setResFilters] = useState({
    resourceType: '',
    action: '',
    organizationId: '',
    actorEmail: '',
    resourceId: '',
    dateFrom: '',
    dateTo: '',
  });
  const [resPerPage, setResPerPage] = useState(50);
  const [orgOptions, setOrgOptions] = useState([]);
  const [selectedResAuditId, setSelectedResAuditId] = useState(null);
  const [resAuditDetail, setResAuditDetail] = useState({ loading: false, data: null, error: '' });
  const [userAudit, setUserAudit] = useState({ items: [], loading: true, error: '' });
  const [opts, setOpts] = useState({
    items: [],
    total: 0,
    page: 1,
    perPage: 25,
    loading: true,
    error: '',
  });
  const [optFilters, setOptFilters] = useState({ category: '', domain: '', optionName: '' });
  const [optModal, setOptModal] = useState({
    open: false,
    saving: false,
    error: '',
    form: emptyPlatformOptionForm(),
  });

  const loadErrors = useCallback(async (page = 1) => {
    setErrList((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch(`/api/admin/application-errors?limit=50&page=${page}`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) {
        setErrList((s) => ({
          ...s,
          loading: false,
          error: data?.error ?? 'Chargement impossible',
        }));
        return;
      }
      setErrList({
        items: data.items ?? [],
        total: data.total ?? 0,
        page: data.page ?? 1,
        perPage: data.perPage ?? 50,
        loading: false,
        error: '',
      });
    } catch {
      setErrList((s) => ({ ...s, loading: false, error: 'Erreur réseau' }));
    }
  }, []);

  const openErrorDetail = useCallback(async (id) => {
    setSelectedErrId(id);
    setErrDetail({ loading: true, data: null, error: '' });
    try {
      const res = await fetch(`/api/admin/application-errors/${id}`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) {
        setErrDetail({ loading: false, data: null, error: data?.error ?? 'Détail introuvable' });
        return;
      }
      setErrDetail({ loading: false, data, error: '' });
    } catch {
      setErrDetail({ loading: false, data: null, error: 'Erreur réseau' });
    }
  }, []);

  const loadResourceAudit = useCallback(
    async (page = 1, opts = {}) => {
      const limit = opts.limit ?? resPerPage;
      const filters = opts.filters ?? resFilters;
      setResAudit((s) => ({ ...s, loading: true, error: '' }));
      try {
        const params = new URLSearchParams();
        params.set('page', String(page));
        params.set('limit', String(limit));
        if (filters.resourceType) params.set('resourceType', filters.resourceType);
        if (filters.action) params.set('action', filters.action);
        if (filters.organizationId) params.set('organizationId', filters.organizationId);
        if (filters.actorEmail.trim()) params.set('actorEmail', filters.actorEmail.trim());
        if (filters.resourceId.trim()) params.set('resourceId', filters.resourceId.trim());
        if (filters.dateFrom) params.set('dateFrom', filters.dateFrom);
        if (filters.dateTo) params.set('dateTo', filters.dateTo);

        const res = await fetch(`/api/admin/resource-audit-logs?${params}`, apiJsonInit());
        const data = await parseJson(res);
        if (!res.ok) {
          setResAudit((s) => ({
            ...s,
            loading: false,
            error: data?.error ?? 'Chargement impossible',
          }));
          return;
        }
        setResAudit({
          items: data.items ?? [],
          total: data.total ?? 0,
          page: data.page ?? 1,
          perPage: data.perPage ?? limit,
          loading: false,
          error: '',
        });
      } catch {
        setResAudit((s) => ({ ...s, loading: false, error: 'Erreur réseau' }));
      }
    },
    [resPerPage, resFilters],
  );

  const openResourceAuditDetail = useCallback(async (id) => {
    setSelectedResAuditId(id);
    setResAuditDetail({ loading: true, data: null, error: '' });
    try {
      const res = await fetch(`/api/admin/resource-audit-logs/${id}`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) {
        setResAuditDetail({ loading: false, data: null, error: data?.error ?? 'Détail introuvable' });
        return;
      }
      setResAuditDetail({ loading: false, data, error: '' });
    } catch {
      setResAuditDetail({ loading: false, data: null, error: 'Erreur réseau' });
    }
  }, []);

  const loadUserAudit = useCallback(async () => {
    setUserAudit({ items: [], loading: true, error: '' });
    try {
      const res = await fetch('/api/users/audit-logs', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok || !Array.isArray(data)) {
        setUserAudit({ items: [], loading: false, error: 'Chargement impossible' });
        return;
      }
      setUserAudit({ items: data, loading: false, error: '' });
    } catch {
      setUserAudit({ items: [], loading: false, error: 'Erreur réseau' });
    }
  }, []);

  const loadPlatformOptions = useCallback(
    async (page = 1, filterOverride = null) => {
      const f = filterOverride ?? optFilters;
      const perPage = opts.perPage;
      setOpts((s) => ({ ...s, loading: true, error: '' }));
      try {
        const params = new URLSearchParams();
        params.set('page', String(page));
        params.set('limit', String(perPage));
        if (f.category.trim()) params.set('category', f.category.trim());
        if (f.domain.trim()) params.set('domain', f.domain.trim());
        if (f.optionName.trim()) params.set('optionName', f.optionName.trim());
        const res = await fetch(`/api/admin/options?${params}`, apiJsonInit());
        const data = await parseJson(res);
        if (!res.ok) {
          setOpts((s) => ({
            ...s,
            loading: false,
            error: data?.error ?? 'Chargement impossible',
          }));
          return;
        }
        setOpts({
          items: data.items ?? [],
          total: data.total ?? 0,
          page: data.page ?? 1,
          perPage: data.perPage ?? perPage,
          loading: false,
          error: '',
        });
      } catch {
        setOpts((s) => ({ ...s, loading: false, error: 'Erreur réseau' }));
      }
    },
    [opts.perPage, optFilters],
  );

  const openCreateOption = useCallback(() => {
    setOptModal({ open: true, saving: false, error: '', form: emptyPlatformOptionForm() });
  }, []);

  const openEditOption = useCallback((row) => {
    setOptModal({
      open: true,
      saving: false,
      error: '',
      form: {
        id: row.id,
        optionName: row.optionName ?? '',
        optionValue: row.optionValue ?? '',
        domain: row.domain ?? '',
        category: row.category ?? '',
        comment: row.comment ?? '',
      },
    });
  }, []);

  const savePlatformOption = useCallback(async () => {
    const { form } = optModal;
    setOptModal((m) => ({ ...m, saving: true, error: '' }));
    const body = {
      optionName: form.optionName.trim(),
      optionValue: form.optionValue,
      category: form.category.trim(),
      domain: form.domain.trim() || null,
      comment: form.comment.trim() || null,
    };
    try {
      const isNew = form.id == null;
      const url = isNew ? '/api/admin/options' : `/api/admin/options/${form.id}`;
      const res = await fetch(url, {
        ...apiJsonInit({
          method: isNew ? 'POST' : 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setOptModal((m) => ({ ...m, saving: false, error: data?.error ?? 'Enregistrement impossible' }));
        return;
      }
      setOptModal({ open: false, saving: false, error: '', form: emptyPlatformOptionForm() });
      void loadPlatformOptions(opts.page);
    } catch {
      setOptModal((m) => ({ ...m, saving: false, error: 'Erreur réseau' }));
    }
  }, [optModal, loadPlatformOptions, opts.page]);

  const deletePlatformOption = useCallback(
    async (id) => {
      if (!window.confirm('Supprimer cette option ?')) return;
      try {
        const res = await fetch(`/api/admin/options/${id}`, apiJsonInit({ method: 'DELETE' }));
        if (!res.ok && res.status !== 204) {
          const data = await parseJson(res);
          setOpts((s) => ({ ...s, error: data?.error ?? 'Suppression impossible' }));
          return;
        }
        void loadPlatformOptions(opts.page);
      } catch {
        setOpts((s) => ({ ...s, error: 'Erreur réseau' }));
      }
    },
    [loadPlatformOptions, opts.page],
  );

  useEffect(() => {
    if (tab === 'errors') void loadErrors(1);
  }, [tab, loadErrors]);

  useEffect(() => {
    if (tab === 'resources') void loadResourceAudit(1);
  }, [tab, loadResourceAudit]);

  useEffect(() => {
    if (tab !== 'resources') return;
    void (async () => {
      try {
        const res = await fetch('/api/organizations', apiJsonInit());
        const data = await parseJson(res);
        if (res.ok && Array.isArray(data)) setOrgOptions(data);
      } catch {
        /* ignore */
      }
    })();
  }, [tab]);

  useEffect(() => {
    if (tab === 'users') void loadUserAudit();
  }, [tab, loadUserAudit]);

  useEffect(() => {
    if (tab === 'options') void loadPlatformOptions(1);
  }, [tab, loadPlatformOptions]);

  return (
    <div className="users-shell admin-supervision-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-triangle-exclamation" aria-hidden />
            <span>Supervision plateforme</span>
          </h1>
          <p className="users-hero-sub muted">
            Erreurs applicatives détaillées, journal des modifications (workflows, connecteurs) et actions sur les comptes.
          </p>
        </div>
      </header>

      <Tabs items={SUPERVISION_TABS} activeId={tab} onChange={setTab} ariaLabel="Sections supervision" />

      {tab === 'errors' ? (
        <div className="content-card" role="tabpanel" id="panel-errors" aria-labelledby="tab-errors">
          <ErrorAlert>{errList.error}</ErrorAlert>
          {errList.loading ? <p className="muted">Chargement…</p> : null}
          {!errList.loading && errList.items.length === 0 && !errList.error ? (
            <p className="muted">Aucune erreur enregistrée pour l’instant.</p>
          ) : null}
          <div className="admin-supervision-split">
            <div className="admin-supervision-list">
              <ul className="admin-error-list">
                {errList.items.map((row) => (
                  <li key={row.id}>
                    <button
                      type="button"
                      className={`admin-error-row ${selectedErrId === row.id ? 'selected' : ''}`}
                      onClick={() => void openErrorDetail(row.id)}
                    >
                      <span className={`badge ${levelBadgeClass(row.level)}`}>{row.level}</span>
                      <span className="mono small">{new Date(row.createdAt).toLocaleString('fr-FR')}</span>
                      <strong>{row.message}</strong>
                      <span className="muted small">{row.exceptionClass ?? row.source}</span>
                    </button>
                  </li>
                ))}
              </ul>
              {errList.total > errList.perPage ? (
                <div className="admin-supervision-pager">
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={errList.page <= 1}
                    onClick={() => void loadErrors(errList.page - 1)}
                  >
                    Précédent
                  </button>
                  <span className="muted small">
                    Page {errList.page} · {errList.total} entrée(s)
                  </span>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={errList.page * errList.perPage >= errList.total}
                    onClick={() => void loadErrors(errList.page + 1)}
                  >
                    Suivant
                  </button>
                </div>
              ) : null}
            </div>
            <div className="admin-supervision-detail">
              {errDetail.loading ? <p className="muted">Chargement du détail…</p> : null}
              <ErrorAlert>{errDetail.error}</ErrorAlert>
              {errDetail.data ? (
                <>
                  <h3>Erreur #{errDetail.data.id}</h3>
                  <div className="admin-error-meta">
                    <span className={`badge ${levelBadgeClass(errDetail.data.level)}`}>{errDetail.data.level}</span>
                    <span>{sourceLabel(errDetail.data.source)}</span>
                    {errDetail.data.httpMethod ? (
                      <span className="mono">
                        {errDetail.data.httpMethod} {errDetail.data.requestUri}
                      </span>
                    ) : null}
                  </div>
                  {errDetail.data.user ? (
                    <p className="small">
                      Utilisateur : <strong>{errDetail.data.user.email}</strong>
                    </p>
                  ) : null}
                  {errDetail.data.organization ? (
                    <p className="small">
                      Organisation : <strong>{errDetail.data.organization.name}</strong>
                    </p>
                  ) : null}
                  <h4>Message</h4>
                  <pre className="admin-error-pre">{errDetail.data.message}</pre>
                  <h4>Détail / chaîne d’exceptions</h4>
                  <pre className="admin-error-pre">{errDetail.data.detail}</pre>
                  {errDetail.data.trace ? (
                    <>
                      <h4>Trace</h4>
                      <pre className="admin-error-pre admin-error-trace">{errDetail.data.trace}</pre>
                    </>
                  ) : null}
                  {errDetail.data.context && Object.keys(errDetail.data.context).length > 0 ? (
                    <>
                      <h4>Contexte</h4>
                      <pre className="admin-error-pre">{JSON.stringify(errDetail.data.context, null, 2)}</pre>
                    </>
                  ) : null}
                  {errDetail.data.clientIp ? (
                    <p className="muted small">
                      IP {errDetail.data.clientIp}
                      {errDetail.data.userAgent ? ` · ${errDetail.data.userAgent}` : ''}
                    </p>
                  ) : null}
                </>
              ) : !errDetail.loading && !errDetail.error && selectedErrId === null ? (
                <p className="muted">Sélectionnez une entrée pour afficher le détail.</p>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      {tab === 'resources' ? (
        <div className="content-card" role="tabpanel" id="panel-resources" aria-labelledby="tab-resources">
          <ErrorAlert>{resAudit.error}</ErrorAlert>
          <ErrorAlert>{resAuditDetail.error}</ErrorAlert>
          {resAudit.loading ? <p className="muted">Chargement…</p> : null}

          <div className="admin-supervision-split admin-supervision-split--resource-audit">
            <div className="admin-supervision-list">
              <div className="users-filters-minimal admin-supervision-resource-filters">
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Type</span>
                  <select
                    value={resFilters.resourceType}
                    onChange={(e) => setResFilters((f) => ({ ...f, resourceType: e.target.value }))}
                  >
                    <option value="">Tous</option>
                    <option value="form_webhook">Workflow</option>
                    <option value="service_connection">Connecteur</option>
                  </select>
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Action</span>
                  <select
                    value={resFilters.action}
                    onChange={(e) => setResFilters((f) => ({ ...f, action: e.target.value }))}
                  >
                    <option value="">Toutes</option>
                    <option value="created">Création</option>
                    <option value="updated">Modification</option>
                    <option value="deleted">Suppression</option>
                  </select>
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Organisation</span>
                  <select
                    value={resFilters.organizationId}
                    onChange={(e) => setResFilters((f) => ({ ...f, organizationId: e.target.value }))}
                  >
                    <option value="">Toutes</option>
                    {orgOptions.map((o) => (
                      <option key={o.id} value={String(o.id)}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">E-mail acteur</span>
                  <input
                    type="search"
                    value={resFilters.actorEmail}
                    onChange={(e) => setResFilters((f) => ({ ...f, actorEmail: e.target.value }))}
                    placeholder="Contient…"
                    autoComplete="off"
                  />
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">ID ressource</span>
                  <input
                    type="search"
                    value={resFilters.resourceId}
                    onChange={(e) => setResFilters((f) => ({ ...f, resourceId: e.target.value }))}
                    placeholder="ex. 42"
                    autoComplete="off"
                  />
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Du</span>
                  <input
                    type="date"
                    value={resFilters.dateFrom}
                    onChange={(e) => setResFilters((f) => ({ ...f, dateFrom: e.target.value }))}
                  />
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Au</span>
                  <input
                    type="date"
                    value={resFilters.dateTo}
                    onChange={(e) => setResFilters((f) => ({ ...f, dateTo: e.target.value }))}
                  />
                </label>
                <label className="users-filter-min">
                  <span className="users-filter-min-label muted">Par page</span>
                  <select
                    value={String(resPerPage)}
                    onChange={(e) => {
                      const n = Number(e.target.value);
                      setResPerPage(n);
                      void loadResourceAudit(1, { limit: n });
                    }}
                  >
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                  </select>
                </label>
                <div className="users-filter-min admin-supervision-filter-actions">
                  <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => void loadResourceAudit(1)}
                  >
                    Filtrer
                  </button>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => {
                      const empty = {
                        resourceType: '',
                        action: '',
                        organizationId: '',
                        actorEmail: '',
                        resourceId: '',
                        dateFrom: '',
                        dateTo: '',
                      };
                      setResFilters(empty);
                      setSelectedResAuditId(null);
                      setResAuditDetail({ loading: false, data: null, error: '' });
                      void loadResourceAudit(1, { filters: empty });
                    }}
                  >
                    Réinitialiser
                  </button>
                </div>
              </div>

              <div className="org-table-wrap">
                <table className="org-table org-table--resource-audit">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Ressource</th>
                      <th>Action</th>
                      <th>Nom / ID</th>
                      <th>Acteur</th>
                      <th>Org.</th>
                      <th>Lien</th>
                      <th>IP</th>
                    </tr>
                  </thead>
                  <tbody>
                    {resAudit.items.length === 0 && !resAudit.loading ? (
                      <tr>
                        <td colSpan={8} className="muted">
                          Aucune entrée.
                        </td>
                      </tr>
                    ) : null}
                    {resAudit.items.map((r) => (
                      <tr
                        key={r.id}
                        className={`admin-resource-audit-row ${selectedResAuditId === r.id ? 'selected' : ''}`}
                      >
                        <td className="muted nowrap">
                          <button
                            type="button"
                            className="admin-resource-audit-row-btn"
                            onClick={() => void openResourceAuditDetail(r.id)}
                          >
                            {new Date(r.occurredAt).toLocaleString('fr-FR')}
                          </button>
                        </td>
                        <td>{r.resourceTypeLabel ?? formatResourceType(r.resourceType)}</td>
                        <td>{r.actionLabel ?? RESOURCE_ACTION_LABELS[r.action] ?? r.action}</td>
                        <td>
                          <span className="small">{r.resourceName ?? '—'}</span>
                          <br />
                          <span className="mono small muted">#{r.resourceId}</span>
                          {r.resourceStillExists === false ? (
                            <span
                              className="badge warn small admin-resource-removed-badge"
                              title="L’objet n’existe plus en base"
                            >
                              supprimé
                            </span>
                          ) : null}
                        </td>
                        <td className="small">{resourceActorLabel(r.actor)}</td>
                        <td className="small">{r.organization?.name ?? '—'}</td>
                        <td>
                          {r.spaPath && r.spaPath !== '/' ? (
                            <a href={r.spaPath} target="_blank" rel="noopener noreferrer" className="small">
                              Ouvrir
                            </a>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="mono small muted">{r.clientIp ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {resAudit.total > resAudit.perPage ? (
                <div className="admin-supervision-pager">
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={resAudit.page <= 1}
                    onClick={() => void loadResourceAudit(resAudit.page - 1)}
                  >
                    Précédent
                  </button>
                  <span className="muted small">
                    Page {resAudit.page} · {resAudit.total} entrée(s)
                  </span>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={resAudit.page * resAudit.perPage >= resAudit.total}
                    onClick={() => void loadResourceAudit(resAudit.page + 1)}
                  >
                    Suivant
                  </button>
                </div>
              ) : null}
            </div>

            <div className="admin-supervision-detail admin-supervision-detail--resource-audit">
              {resAuditDetail.loading ? <p className="muted">Chargement du détail…</p> : null}
              {resAuditDetail.data ? (
                <ResourceAuditDetailBody data={resAuditDetail.data} />
              ) : !resAuditDetail.loading && !resAuditDetail.error && selectedResAuditId === null ? (
                <p className="muted">Cliquez sur une date dans la liste pour afficher le détail (modifications, instantanés, JSON brut).</p>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      {tab === 'users' ? (
        <div className="content-card" role="tabpanel" id="panel-users" aria-labelledby="tab-users">
          <ErrorAlert>{userAudit.error}</ErrorAlert>
          {userAudit.loading ? <p className="muted">Chargement…</p> : null}
          <div className="org-table-wrap">
            <table className="org-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Action</th>
                  <th>Acteur</th>
                  <th>Cible</th>
                  <th>Organisation</th>
                </tr>
              </thead>
              <tbody>
                {userAudit.items.map((log) => (
                  <tr key={log.id}>
                    <td className="muted nowrap">{new Date(log.occurredAt).toLocaleString('fr-FR')}</td>
                    <td>{USER_ACTION_LABELS[log.action] ?? log.action}</td>
                    <td>{log.actor ? log.actor.email : '—'}</td>
                    <td>{log.targetEmail ?? '—'}</td>
                    <td>{log.organization ? log.organization.name : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}

      {tab === 'options' ? (
        <div className="content-card" role="tabpanel" id="panel-options" aria-labelledby="tab-options">
          <ErrorAlert>{opts.error}</ErrorAlert>
          <ErrorAlert>{optModal.error}</ErrorAlert>
          <p className="muted small" style={{ marginTop: 0 }}>
            Paramètres clé/valeur (réservés aux administrateurs). Table SQL <span className="mono">app_option</span> —{' '}
            pas ApiPlatform sur ce projet.
          </p>
          <div className="users-filters-minimal admin-supervision-resource-filters">
            <label className="users-filter-min">
              <span className="users-filter-min-label muted">Catégorie</span>
              <input
                type="search"
                value={optFilters.category}
                onChange={(e) => setOptFilters((f) => ({ ...f, category: e.target.value }))}
                placeholder="Exact…"
                autoComplete="off"
              />
            </label>
            <label className="users-filter-min">
              <span className="users-filter-min-label muted">Domaine</span>
              <input
                type="search"
                value={optFilters.domain}
                onChange={(e) => setOptFilters((f) => ({ ...f, domain: e.target.value }))}
                placeholder="Exact…"
                autoComplete="off"
              />
            </label>
            <label className="users-filter-min">
              <span className="users-filter-min-label muted">Nom d’option</span>
              <input
                type="search"
                value={optFilters.optionName}
                onChange={(e) => setOptFilters((f) => ({ ...f, optionName: e.target.value }))}
                placeholder="Contient…"
                autoComplete="off"
              />
            </label>
            <div className="users-filter-min admin-supervision-filter-actions">
              <button type="button" className="btn btn-primary" onClick={() => void loadPlatformOptions(1)}>
                Filtrer
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => {
                  const empty = { category: '', domain: '', optionName: '' };
                  setOptFilters(empty);
                  void loadPlatformOptions(1, empty);
                }}
              >
                Réinitialiser
              </button>
              <button type="button" className="btn btn-primary" onClick={openCreateOption}>
                Nouvelle option
              </button>
            </div>
          </div>

          {opts.loading ? <p className="muted">Chargement…</p> : null}
          <div className="org-table-wrap">
            <table className="org-table org-table--resource-audit">
              <thead>
                <tr>
                  <th>Id</th>
                  <th>Catégorie</th>
                  <th>Domaine</th>
                  <th>Nom</th>
                  <th>Valeur</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {opts.items.length === 0 && !opts.loading ? (
                  <tr>
                    <td colSpan={6} className="muted">
                      Aucune option. Créez-en une ou modifiez les filtres.
                    </td>
                  </tr>
                ) : null}
                {opts.items.map((row) => (
                  <tr key={row.id}>
                    <td className="mono muted">{row.id}</td>
                    <td>{row.category}</td>
                    <td>{row.domain ?? '—'}</td>
                    <td className="mono small">{row.optionName}</td>
                    <td className="small">
                      {row.optionValue && row.optionValue.length > 80
                        ? `${row.optionValue.slice(0, 77)}…`
                        : row.optionValue}
                    </td>
                    <td className="nowrap">
                      <button type="button" className="btn btn-secondary small" onClick={() => openEditOption(row)}>
                        Modifier
                      </button>{' '}
                      <button
                        type="button"
                        className="btn btn-secondary small"
                        onClick={() => void deletePlatformOption(row.id)}
                      >
                        Supprimer
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {opts.total > opts.perPage ? (
            <div className="admin-supervision-pager">
              <button
                type="button"
                className="btn btn-secondary"
                disabled={opts.page <= 1}
                onClick={() => void loadPlatformOptions(opts.page - 1)}
              >
                Précédent
              </button>
              <span className="muted small">
                Page {opts.page} · {opts.total} entrée(s)
              </span>
              <button
                type="button"
                className="btn btn-secondary"
                disabled={opts.page * opts.perPage >= opts.total}
                onClick={() => void loadPlatformOptions(opts.page + 1)}
              >
                Suivant
              </button>
            </div>
          ) : null}

          {optModal.open ? (
            <div
              className="modal-backdrop"
              role="dialog"
              aria-modal="true"
              aria-labelledby="admin-option-modal-title"
              onClick={(e) => e.target === e.currentTarget && !optModal.saving && setOptModal((m) => ({ ...m, open: false }))}
              onKeyDown={(e) => e.key === 'Escape' && !optModal.saving && setOptModal((m) => ({ ...m, open: false }))}
            >
              <div className="modal-panel admin-option-modal-panel">
                <div className="modal-panel-header">
                  <h3 id="admin-option-modal-title">
                    {optModal.form.id == null ? 'Nouvelle option' : `Option #${optModal.form.id}`}
                  </h3>
                  <button
                    type="button"
                    className="modal-close"
                    aria-label="Fermer"
                    disabled={optModal.saving}
                    onClick={() => setOptModal((m) => ({ ...m, open: false }))}
                  >
                    ×
                  </button>
                </div>
                <label className="field">
                  <span>Catégorie</span>
                  <input
                    value={optModal.form.category}
                    onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, category: e.target.value } }))}
                    required
                  />
                </label>
                <label className="field">
                  <span>Domaine (optionnel)</span>
                  <input
                    value={optModal.form.domain}
                    onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, domain: e.target.value } }))}
                  />
                </label>
                <label className="field">
                  <span>Nom d’option</span>
                  <input
                    value={optModal.form.optionName}
                    onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, optionName: e.target.value } }))}
                    required
                  />
                </label>
                <label className="field">
                  <span>Valeur</span>
                  <textarea
                    className="admin-option-value-textarea"
                    rows={6}
                    value={optModal.form.optionValue}
                    onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, optionValue: e.target.value } }))}
                    required
                  />
                </label>
                <label className="field">
                  <span>Commentaire (optionnel)</span>
                  <textarea
                    rows={3}
                    value={optModal.form.comment}
                    onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, comment: e.target.value } }))}
                  />
                </label>
                <div className="modal-actions">
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={optModal.saving}
                    onClick={() => setOptModal((m) => ({ ...m, open: false }))}
                  >
                    Annuler
                  </button>
                  <button type="button" className="btn btn-primary" disabled={optModal.saving} onClick={() => void savePlatformOption()}>
                    {optModal.saving ? 'Enregistrement…' : 'Enregistrer'}
                  </button>
                </div>
              </div>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function resourceActorLabel(actor) {
  if (!actor) return '—';
  const name = actor.displayName;
  const em = actor.email ?? '';
  if (name && em) return `${name} · ${em}`;
  if (em) return em;
  return '—';
}

function DiffChangeBlock({ ch }) {
  const kind = ch.kind;
  if (kind === 'scalar') {
    return (
      <li className="admin-diff-scalar">
        <strong>{ch.label || ch.key}</strong>
        <div className="muted small">
          Avant : {ch.beforeDisplay ?? String(ch.before ?? '—')}
        </div>
        <div className="small">
          Après : {ch.afterDisplay ?? String(ch.after ?? '—')}
        </div>
      </li>
    );
  }
  if (kind === 'actions' && Array.isArray(ch.items)) {
    return (
      <li className="admin-diff-actions">
        <strong>{ch.label || 'Actions du flux'}</strong>
        <ul className="admin-diff-action-items">
          {ch.items.map((item, j) => (
            <li key={j}>
              <span className="small">{item.changeTypeLabel || item.changeType}</span>
              {Array.isArray(item.fieldChanges)
                ? item.fieldChanges.map((fc, k) => (
                    <div key={k} className="admin-diff-fc small">
                      <strong>{fc.label || fc.key}</strong> : {fc.beforeDisplay} → {fc.afterDisplay}
                    </div>
                  ))
                : null}
            </li>
          ))}
        </ul>
      </li>
    );
  }
  return (
    <li className="mono small">
      <pre className="admin-error-pre">{JSON.stringify(ch, null, 2)}</pre>
    </li>
  );
}

function ResourceAuditDetailBody({ data }) {
  const dp = data.detailsPresented;

  return (
    <>
      <h3>
        {data.resourceTypeLabel} · {data.actionLabel}{' '}
        <span className="mono muted">#{data.resourceId}</span>
      </h3>
      <p className="small muted">
        {new Date(data.occurredAt).toLocaleString('fr-FR')}
        {data.clientIp ? ` · IP ${data.clientIp}` : ''}
      </p>
      <p className="small">
        <strong>Nom :</strong> {data.resourceName ?? '—'}{' '}
        {data.resourceStillExists === false ? (
          <span className="badge warn small">objet absent en base</span>
        ) : null}
      </p>
      <p className="small">
        <strong>Acteur :</strong> {resourceActorLabel(data.actor)}
      </p>
      <p className="small">
        <strong>Organisation :</strong> {data.organization?.name ?? '—'}
      </p>
      {data.spaPath && data.spaPath !== '/' ? (
        <p className="small">
          <a href={data.spaPath} target="_blank" rel="noopener noreferrer">
            Ouvrir la ressource (nouvel onglet)
          </a>
          {data.spaOpenHint ? <div className="muted small">{data.spaOpenHint}</div> : null}
        </p>
      ) : null}

      {dp?.snapshotNameAtCreation ? (
        <p className="small">
          <strong>Nom enregistré à la création :</strong> {dp.snapshotNameAtCreation}
        </p>
      ) : null}

      {dp && typeof dp.auditSummary === 'string' && dp.auditSummary ? (
        <>
          <h4>Résumé</h4>
          <p className="small">{dp.auditSummary}</p>
        </>
      ) : null}

      {dp?.diff?.changes ? (
        <>
          <h4>Modifications</h4>
          <ul className="admin-resource-audit-diff">
            {dp.diff.changes.map((ch, i) => (
              <DiffChangeBlock key={i} ch={ch} />
            ))}
          </ul>
        </>
      ) : null}

      {dp?.changedKeysLabels?.length ? (
        <p className="small muted">Clés modifiées : {dp.changedKeysLabels.join(', ')}</p>
      ) : null}

      {dp?.snapshotReadable ? (
        <>
          <h4>Instantané (connecteur)</h4>
          <pre className="admin-error-pre">{JSON.stringify(dp.snapshotReadable, null, 2)}</pre>
        </>
      ) : null}
      {dp?.requestKeys?.length ? (
        <p className="small">Champs présents dans la requête de mise à jour : {dp.requestKeys.join(', ')}</p>
      ) : null}

      {data.details && typeof data.details === 'object' && Object.keys(data.details).length > 0 ? (
        <details className="admin-resource-audit-raw">
          <summary>Données brutes enregistrées (JSON)</summary>
          <pre className="admin-error-pre admin-error-pre--tall">{JSON.stringify(data.details, null, 2)}</pre>
        </details>
      ) : null}
    </>
  );
}

function formatResourceType(t) {
  return RESOURCE_TYPE_LABELS[t] ?? t;
}
