import { useCallback, useEffect, useMemo, useState } from 'react';
import { absoluteAppPath } from '../lib/paths.js';
import { apiJsonInit, parseJson } from '../lib/http.js';

/**
 * @param {{ user: object }} props
 */
export default function SeoInsights({ user }) {
  const orgId = user.organization?.id;
  const canManage = user.roles.includes('ROLE_ADMIN') || user.roles.includes('ROLE_MANAGER');
  const [org, setOrg] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [provider, setProvider] = useState('ollama');
  const [model, setModel] = useState('mistral');
  const [baseUrl, setBaseUrl] = useState('http://127.0.0.1:11434');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  const orgUrl = useMemo(() => {
    if (orgId == null) return '';
    return absoluteAppPath(`/api/organizations/${orgId}`);
  }, [orgId]);

  const load = useCallback(async () => {
    if (!orgUrl) return;
    setLoading(true);
    setError('');
    try {
      const r = await fetch(orgUrl, apiJsonInit({ method: 'GET' }));
      const data = await parseJson(r);
      if (!r.ok) {
        setError((data && data.error) || 'Chargement impossible.');
        return;
      }
      setOrg(data);
      const ai = data.aiSettings && typeof data.aiSettings === 'object' ? data.aiSettings : {};
      setProvider(typeof ai.provider === 'string' && ai.provider ? ai.provider : 'ollama');
      setModel(typeof ai.model === 'string' && ai.model ? ai.model : 'mistral');
      setBaseUrl(typeof ai.baseUrl === 'string' && ai.baseUrl ? ai.baseUrl : 'http://127.0.0.1:11434');
    } catch {
      setError('Erreur réseau.');
    } finally {
      setLoading(false);
    }
  }, [orgUrl]);

  useEffect(() => {
    void load();
  }, [load]);

  const saveAi = async () => {
    if (!orgUrl || !canManage) return;
    setSaving(true);
    setNotice(null);
    setError('');
    try {
      const r = await fetch(
        orgUrl,
        apiJsonInit({
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: org?.name,
            aiSettings: {
              provider,
              model,
              baseUrl: baseUrl.trim(),
            },
          }),
        }),
      );
      const data = await parseJson(r);
      if (!r.ok) {
        setError((data && data.error) || 'Enregistrement impossible.');
        return;
      }
      setOrg(data);
      setNotice({ type: 'ok', text: 'Configuration IA enregistrée pour cette organisation.' });
    } catch {
      setError('Erreur réseau.');
    } finally {
      setSaving(false);
    }
  };

  if (orgId == null) {
    return (
      <div className="users-shell org-section seo-insights-page">
        <p className="muted">Aucune organisation active.</p>
      </div>
    );
  }

  return (
    <div className="users-shell org-section seo-insights-page">
      <header className="users-hero users-hero--minimal seo-insights-hero">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-wand-magic-sparkles" aria-hidden />
            <span> SEO &amp; IA</span>
          </h1>
          <p className="users-hero-sub muted">
            Paramètres multi-tenant pour les agents (Ollama par défaut). Les scores et recommandations détaillés
            apparaissent dans les journaux d’exécution des workflows après passage des actions{' '}
            <code>ai_action</code> et <code>parse_json</code>.
          </p>
        </div>
      </header>

      <div className="content-card">
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

        {!loading && org ? (
          <>
            <section className="integrations-block">
              <h3 className="integrations-block__title">Résumé</h3>
              <ul className="small muted" style={{ paddingLeft: '1.2rem' }}>
                <li>
                  <strong>Mots-clés GSC</strong> : récupérés dans l’étape <code>gsc_fetch</code> (cache 24 h par
                  organisation et URL).
                </li>
                <li>
                  <strong>Score SEO</strong> : champ <code>seo_score</code> du JSON après <code>parse_json</code> (clé{' '}
                  <code>data.seo</code> dans le pipeline).
                </li>
                <li>
                  <strong>Recommandations</strong> : tableau <code>improvements</code> du même JSON — visible dans le
                  journal de l’action concernée.
                </li>
              </ul>
            </section>

            {canManage ? (
              <section className="integrations-block" style={{ marginTop: '1.5rem' }}>
                <h3 className="integrations-block__title">Fournisseur IA (organisation)</h3>
                <p className="muted small">Actuellement : Ollama uniquement ; d’autres providers pourront s’ajouter.</p>
                <div className="org-form-grid" style={{ maxWidth: '32rem' }}>
                  <label className="form-field">
                    <span className="form-label">Provider</span>
                    <input className="form-control" value={provider} onChange={(e) => setProvider(e.target.value)} />
                  </label>
                  <label className="form-field">
                    <span className="form-label">Modèle</span>
                    <input className="form-control" value={model} onChange={(e) => setModel(e.target.value)} />
                  </label>
                  <label className="form-field">
                    <span className="form-label">URL de base Ollama</span>
                    <input className="form-control" value={baseUrl} onChange={(e) => setBaseUrl(e.target.value)} />
                  </label>
                </div>
                <button type="button" className="btn btn-primary" style={{ marginTop: '1rem' }} disabled={saving} onClick={() => void saveAi()}>
                  {saving ? 'Enregistrement…' : 'Enregistrer'}
                </button>
              </section>
            ) : (
              <p className="muted small">Seuls les gestionnaires peuvent modifier la configuration IA.</p>
            )}
          </>
        ) : null}
      </div>
    </div>
  );
}
