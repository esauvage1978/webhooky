import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import ModalPortal from '../components/ModalPortal.jsx';
import ErrorAlert from '../components/ui/ErrorAlert.jsx';
import { apiJsonInit, parseJson } from '../lib/http.js';

/** Réponse API `meta/choices` : tableau attendu ; tolère une chaîne « a;b » (séparateur ; ou ; pleine chasse). */
function normalizeMetaChoicesList(value) {
  if (Array.isArray(value)) {
    return value.map((s) => String(s).trim()).filter(Boolean);
  }
  if (typeof value === 'string' && value.trim() !== '') {
    return value
      .replace(/\uFF1B/g, ';')
      .split(/\s*;\s*/)
      .map((s) => s.trim())
      .filter(Boolean);
  }
  return [];
}

/** Si la valeur en base n’est pas dans la liste méta, on l’affiche quand même dans le select. */
function orderedChoicesWithOrphan(choices, current) {
  const list = Array.isArray(choices) ? choices : [];
  const c = typeof current === 'string' ? current.trim() : '';
  if (!c) return list;
  if (list.includes(c)) return list;
  return [...list, c];
}

const emptyPlatformOptionForm = () => ({
  id: null,
  optionName: '',
  optionValue: '',
  domain: '',
  category: '',
  comment: '',
});

/**
 * CRUD options (app_option) — API /api/admin/options (ROLE_ADMIN côté serveur).
 * @param {object} [props]
 * @param {Record<string, unknown>} [props.contentCardProps] — ex. role tabpanel pour Supervision
 * @param {boolean} [props.showHubLayout] — page dédiée : hero façon Intégrations + carte pleine largeur
 */
export default function AdminPlatformOptions({ contentCardProps = {}, showHubLayout = false }) {
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
  const [optionMeta, setOptionMeta] = useState({ categories: [], domains: [], error: '' });

  const loadOptionMeta = useCallback(async () => {
    try {
      const res = await fetch('/api/admin/options/meta/choices', apiJsonInit());
      const data = (await parseJson(res)) ?? {};
      if (!res.ok) {
        setOptionMeta({ categories: [], domains: [], error: data?.error ?? 'Listes indisponibles' });
        return;
      }
      setOptionMeta({
        categories: normalizeMetaChoicesList(data.categories),
        domains: normalizeMetaChoicesList(data.domains),
        error: '',
      });
    } catch {
      setOptionMeta({ categories: [], domains: [], error: 'Erreur réseau (listes)' });
    }
  }, []);

  useEffect(() => {
    void loadOptionMeta();
  }, [loadOptionMeta]);

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
    void loadOptionMeta();
  }, [loadOptionMeta]);

  const openEditOption = useCallback(
    (row) => {
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
      void loadOptionMeta();
    },
    [loadOptionMeta],
  );

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
      void loadOptionMeta();
      void loadPlatformOptions(opts.page);
    } catch {
      setOptModal((m) => ({ ...m, saving: false, error: 'Erreur réseau' }));
    }
  }, [optModal, loadOptionMeta, loadPlatformOptions, opts.page]);

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
    void loadPlatformOptions(1);
  }, []);

  const cardClassName = [
    'content-card',
    showHubLayout ? 'content-card--integrations-wide' : '',
    contentCardProps.className,
  ]
    .filter(Boolean)
    .join(' ');
  const { className: _omit, ...restCardProps } = contentCardProps;

  const categoryFilterChoices = useMemo(
    () => orderedChoicesWithOrphan(optionMeta.categories, optFilters.category),
    [optionMeta.categories, optFilters.category],
  );
  const domainFilterChoices = useMemo(
    () => orderedChoicesWithOrphan(optionMeta.domains, optFilters.domain),
    [optionMeta.domains, optFilters.domain],
  );
  const modalCategoryChoices = useMemo(
    () => orderedChoicesWithOrphan(optionMeta.categories, optModal.form.category),
    [optionMeta.categories, optModal.form.category],
  );
  const modalDomainChoices = useMemo(
    () => orderedChoicesWithOrphan(optionMeta.domains, optModal.form.domain),
    [optionMeta.domains, optModal.form.domain],
  );

  const filterFields = (
    <>
      <label className="users-filter-min">
        <span className="users-filter-min-label muted">Domaine</span>
        <select
          value={optFilters.domain}
          onChange={(e) => setOptFilters((f) => ({ ...f, domain: e.target.value }))}
        >
          <option value="">Tous</option>
          {domainFilterChoices.map((v) => (
            <option key={v} value={v}>
              {v}
            </option>
          ))}
        </select>
      </label>
      <label className="users-filter-min">
        <span className="users-filter-min-label muted">Catégorie</span>
        <select
          value={optFilters.category}
          onChange={(e) => setOptFilters((f) => ({ ...f, category: e.target.value }))}
        >
          <option value="">Toutes</option>
          {categoryFilterChoices.map((v) => (
            <option key={v} value={v}>
              {v}
            </option>
          ))}
        </select>
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
    </>
  );

  const filterActions = (
    <>
      <button
        type="button"
        className={showHubLayout ? 'btn secondary small' : 'btn btn-primary'}
        onClick={() => void loadPlatformOptions(1)}
      >
        Filtrer
      </button>
      <button
        type="button"
        className={showHubLayout ? 'btn secondary small' : 'btn btn-secondary'}
        onClick={() => {
          const empty = { category: '', domain: '', optionName: '' };
          setOptFilters(empty);
          void loadPlatformOptions(1, empty);
        }}
      >
        Réinitialiser
      </button>
      {!showHubLayout ? (
        <button type="button" className="btn btn-primary" onClick={openCreateOption}>
          Nouvelle option
        </button>
      ) : null}
    </>
  );

  return (
    <Fragment>
      {showHubLayout ? (
        <header className="users-hero users-hero--minimal">
          <div className="integrations-hero-intro">
            <h1 className="users-hero-title">
              <i className="fa-solid fa-sliders" aria-hidden />
              <span>Options plateforme</span>
            </h1>
          </div>
          <div className="users-hero-actions wp-projects-hero-actions">
            <button type="button" className="fw-btn-primary wp-proj-hero-btn" onClick={openCreateOption}>
              <i className="fa-solid fa-plus" aria-hidden />
              <span>Nouvelle option</span>
            </button>
          </div>
        </header>
      ) : null}

      <div className={cardClassName} {...restCardProps}>
        <ErrorAlert>{opts.error}</ErrorAlert>
        {optionMeta.error ? <p className="muted small">{optionMeta.error}</p> : null}
        {!showHubLayout ? (
          <p className="muted small" style={{ marginTop: 0 }}>
            Paramètres clé/valeur (réservés aux administrateurs). Table SQL <span className="mono">app_option</span>.
          </p>
        ) : null}

        {showHubLayout ? (
          <div className="admin-options-toolbar">
            <div className="admin-options-toolbar-fields">{filterFields}</div>
            <div className="admin-options-toolbar-actions">{filterActions}</div>
          </div>
        ) : (
          <div className="users-filters-minimal admin-supervision-resource-filters">
            {filterFields}
            <div className="users-filter-min admin-supervision-filter-actions">{filterActions}</div>
          </div>
        )}

        {opts.loading ? <p className="muted">Chargement…</p> : null}
        <div className={showHubLayout ? 'integrations-block__table-wrap org-table-wrap' : 'org-table-wrap'}>
        <table className={showHubLayout ? 'org-table mailjet-table' : 'org-table org-table--resource-audit'}>
          <thead>
            <tr>
              <th>Id</th>
              <th>Domaine</th>
              <th>Catégorie</th>
              <th>Nom</th>
              <th>Commentaire</th>
              <th>Valeur</th>
              <th className="org-table-th-actions" aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {opts.items.length === 0 && !opts.loading ? (
              <tr>
                <td colSpan={7} className="muted">
                  Aucune option. Créez-en une ou modifiez les filtres.
                </td>
              </tr>
            ) : null}
            {opts.items.map((row) => (
              <tr key={row.id}>
                <td className="mono muted">{row.id}</td>
                <td>{row.domain ?? '—'}</td>
                <td>{row.category}</td>
                <td className="mono small">{row.optionName}</td>
                <td className="small muted">
                  {row.comment && row.comment.length > 60 ? `${row.comment.slice(0, 57)}…` : row.comment ?? '—'}
                </td>
                <td className="small">
                  {row.optionValue && row.optionValue.length > 80
                    ? `${row.optionValue.slice(0, 77)}…`
                    : row.optionValue}
                </td>
                <td className={showHubLayout ? 'actions' : 'nowrap'}>
                  {showHubLayout ? (
                    <div className="org-table-actions-inner">
                      <button
                        type="button"
                        className="btn secondary small org-table-action-btn"
                        onClick={() => openEditOption(row)}
                      >
                        <i className="fa-solid fa-pen-to-square" aria-hidden />
                        <span>Modifier</span>
                      </button>
                      <button
                        type="button"
                        className="btn danger small org-table-action-btn"
                        onClick={() => void deletePlatformOption(row.id)}
                      >
                        <i className="fa-solid fa-trash-can" aria-hidden />
                        <span>Supprimer</span>
                      </button>
                    </div>
                  ) : (
                    <>
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
                    </>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {opts.total > opts.perPage ? (
        <div className={showHubLayout ? 'admin-options-pager' : 'admin-supervision-pager'}>
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
    </div>

      {optModal.open ? (
        <ModalPortal>
        <div
          className="modal-backdrop"
          role="dialog"
          aria-modal="true"
          aria-labelledby="admin-option-modal-title"
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
            <ErrorAlert>{optModal.error}</ErrorAlert>
            {optionMeta.error ? <p className="muted small">{optionMeta.error}</p> : null}
            <label className="field">
              <span>Domaine (optionnel)</span>
              <select
                value={optModal.form.domain ?? ''}
                onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, domain: e.target.value } }))}
              >
                <option value="">Aucun</option>
                {modalDomainChoices.map((v) => (
                  <option key={v} value={v}>
                    {v}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Catégorie</span>
              <select
                value={optModal.form.category}
                onChange={(e) => setOptModal((m) => ({ ...m, form: { ...m.form, category: e.target.value } }))}
                required
              >
                <option value="" disabled={modalCategoryChoices.length > 0}>
                  Choisir…
                </option>
                {modalCategoryChoices.map((v) => (
                  <option key={v} value={v}>
                    {v}
                  </option>
                ))}
              </select>
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
        </ModalPortal>
      ) : null}
    </Fragment>
  );
}
