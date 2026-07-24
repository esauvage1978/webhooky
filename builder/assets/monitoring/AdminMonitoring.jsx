import { useCallback, useEffect, useState } from 'react';
import ErrorAlert from '../components/ui/ErrorAlert.jsx';
import Tabs from '../components/ui/Tabs.jsx';
import { apiJsonInit, apiPostJson, parseJson } from '../lib/http.js';
import './monitoring.css';

const TABS = [
  { id: 'overview', label: 'Vue d’ensemble' },
  { id: 'pipeline', label: 'Pipeline' },
  { id: 'events', label: 'Événements' },
  { id: 'alerts', label: 'Alertes' },
  { id: 'incidents', label: 'Incidents' },
  { id: 'costs', label: 'Coûts' },
  { id: 'accounts', label: 'Comptes' },
  { id: 'settings', label: 'Réglages' },
];

const PERIODS = [
  { id: '1h', label: '1 h' },
  { id: '24h', label: '24 h' },
  { id: '7d', label: '7 j' },
  { id: '30d', label: '30 j' },
];

function healthClass(status) {
  if (status === 'healthy') return 'mon-health--ok';
  if (status === 'degraded') return 'mon-health--warn';
  if (status === 'critical') return 'mon-health--crit';
  return 'mon-health--unk';
}

function pipeClass(status) {
  if (status === 'ok') return 'mon-pipe__step--ok';
  if (status === 'warn') return 'mon-pipe__step--warn';
  if (status === 'error') return 'mon-pipe__step--err';
  if (status === 'na') return 'mon-pipe__step--na';
  return 'mon-pipe__step--unk';
}

function formatCents(cents, currency = 'EUR') {
  if (cents == null) return '—';
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).format(cents / 100);
}

function MiniBars({ series }) {
  if (!series?.length) {
    return <p className="mon-empty">Pas encore assez d’historique pour un graphique.</p>;
  }
  const max = Math.max(1, ...series.map((p) => p.received || 0));
  return (
    <div className="mon-bars" role="img" aria-label="Volume horaire">
      {series.map((p) => (
        <div key={p.t} className="mon-bars__col" title={`${p.t}: ${p.received} reçus, ${p.error} erreurs`}>
          <div className="mon-bars__stack">
            <div className="mon-bars__err" style={{ height: `${((p.error || 0) / max) * 100}%` }} />
            <div className="mon-bars__ok" style={{ height: `${((p.success || 0) / max) * 100}%` }} />
          </div>
        </div>
      ))}
    </div>
  );
}

export default function AdminMonitoring() {
  const [tab, setTab] = useState('overview');
  const [period, setPeriod] = useState('24h');
  const [overview, setOverview] = useState({ loading: true, error: '', data: null });
  const [events, setEvents] = useState({ loading: false, error: '', items: [], total: 0, page: 1 });
  const [selectedEvent, setSelectedEvent] = useState(null);
  const [alerts, setAlerts] = useState({ loading: false, error: '', items: [], total: 0 });
  const [incidents, setIncidents] = useState({ loading: false, error: '', items: [], total: 0 });
  const [costs, setCosts] = useState({ loading: false, error: '', data: null });
  const [accounts, setAccounts] = useState({ loading: false, error: '', items: [] });
  const [settingsText, setSettingsText] = useState('{}');
  const [settingsMsg, setSettingsMsg] = useState('');
  const [priceForm, setPriceForm] = useState({ channel: 'sms', provider: 'smsfactor_sms', label: '', unitCostCents: 5, currency: 'EUR' });

  const loadOverview = useCallback(async (p = period) => {
    setOverview((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch(`/api/admin/monitoring/overview?period=${encodeURIComponent(p)}`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Chargement impossible');
      setOverview({ loading: false, error: '', data });
    } catch (e) {
      setOverview({ loading: false, error: e.message || 'Erreur', data: null });
    }
  }, [period]);

  useEffect(() => {
    loadOverview(period);
  }, [loadOverview, period]);

  useEffect(() => {
    if (tab === 'events') loadEvents(1);
    if (tab === 'alerts') loadAlerts();
    if (tab === 'incidents') loadIncidents();
    if (tab === 'costs') loadCosts();
    if (tab === 'accounts') loadAccounts();
    if (tab === 'settings') loadSettings();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab]);

  async function loadEvents(page = 1) {
    setEvents((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch(`/api/admin/monitoring/events?page=${page}&limit=40`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setEvents({ loading: false, error: '', items: data.items || [], total: data.total || 0, page });
    } catch (e) {
      setEvents((s) => ({ ...s, loading: false, error: e.message }));
    }
  }

  async function openEvent(id) {
    const res = await fetch(`/api/admin/monitoring/events/${id}`, apiJsonInit());
    const data = await parseJson(res);
    if (res.ok) setSelectedEvent(data);
  }

  async function loadAlerts() {
    setAlerts((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/admin/monitoring/alerts?limit=50', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setAlerts({ loading: false, error: '', items: data.items || [], total: data.total || 0 });
    } catch (e) {
      setAlerts((s) => ({ ...s, loading: false, error: e.message }));
    }
  }

  async function ackAlert(id) {
    await apiPostJson(`/api/admin/monitoring/alerts/${id}/acknowledge`, { body: '{}' });
    loadAlerts();
    loadOverview();
  }

  async function loadIncidents() {
    setIncidents((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/admin/monitoring/incidents?limit=50', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setIncidents({ loading: false, error: '', items: data.items || [], total: data.total || 0 });
    } catch (e) {
      setIncidents((s) => ({ ...s, loading: false, error: e.message }));
    }
  }

  async function resolveIncident(id) {
    await apiPostJson(`/api/admin/monitoring/incidents/${id}/resolve`, { body: '{}' });
    loadIncidents();
  }

  async function loadCosts() {
    setCosts((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/admin/monitoring/costs', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setCosts({ loading: false, error: '', data });
    } catch (e) {
      setCosts({ loading: false, error: e.message, data: null });
    }
  }

  async function createPrice(e) {
    e.preventDefault();
    const res = await fetch('/api/admin/monitoring/pricing-rules', {
      ...apiJsonInit(),
      method: 'POST',
      body: JSON.stringify({ ...priceForm, unitCostCents: Number(priceForm.unitCostCents), active: true }),
    });
    const data = await parseJson(res);
    if (!res.ok) {
      setCosts((s) => ({ ...s, error: data?.error || 'Création impossible' }));
      return;
    }
    loadCosts();
  }

  async function loadAccounts() {
    setAccounts((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/admin/monitoring/accounts', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setAccounts({ loading: false, error: '', items: data.items || [] });
    } catch (e) {
      setAccounts({ loading: false, error: e.message, items: [] });
    }
  }

  async function loadSettings() {
    const res = await fetch('/api/admin/monitoring/settings', apiJsonInit());
    const data = await parseJson(res);
    if (res.ok) setSettingsText(JSON.stringify(data.settings || {}, null, 2));
  }

  async function saveSettings(e) {
    e.preventDefault();
    setSettingsMsg('');
    try {
      const settings = JSON.parse(settingsText);
      const res = await fetch('/api/admin/monitoring/settings', {
        ...apiJsonInit(),
        method: 'PUT',
        body: JSON.stringify({ settings }),
      });
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setSettingsText(JSON.stringify(data.settings || {}, null, 2));
      setSettingsMsg('Enregistré.');
    } catch (err) {
      setSettingsMsg(err.message || 'JSON invalide');
    }
  }

  const d = overview.data;
  const k = d?.kpis;

  return (
    <div className="mon-page">
      <header className="mon-page__head">
        <div>
          <h1 className="mon-page__title">Tour de contrôle</h1>
          <p className="mon-page__sub">Monitoring plateforme — données réelles, pipeline honnête</p>
        </div>
        <div className="mon-periods">
          {PERIODS.map((p) => (
            <button
              key={p.id}
              type="button"
              className={`mon-periods__btn ${period === p.id ? 'is-active' : ''}`}
              onClick={() => setPeriod(p.id)}
            >
              {p.label}
            </button>
          ))}
        </div>
      </header>

      <Tabs items={TABS} activeId={tab} onChange={setTab} />

      {overview.error && tab === 'overview' ? <ErrorAlert message={overview.error} /> : null}

      {tab === 'overview' && (
        <section className="mon-section">
          {overview.loading && !d ? <p className="mon-muted">Chargement…</p> : null}
          {d && (
            <>
              <div className={`mon-health ${healthClass(d.health?.status)}`}>
                <div className="mon-health__score">{d.health?.score ?? '—'}</div>
                <div>
                  <div className="mon-health__label">Score de santé</div>
                  <div className="mon-health__status">{d.health?.status || 'unknown'}</div>
                  <div className="mon-health__meta">Échantillon {d.health?.sampleSize ?? 0} runs</div>
                </div>
                <ul className="mon-health__factors">
                  {(d.health?.factors || []).map((f) => (
                    <li key={f.key}>
                      <span>{f.label}</span>
                      <strong>{f.value == null ? 'n/a' : Math.round(f.value * 100) + '%'}</strong>
                    </li>
                  ))}
                </ul>
              </div>

              <div className="mon-kpi-grid">
                {[
                  ['Reçus', k?.received],
                  ['Succès', k?.success],
                  ['Erreurs', k?.error],
                  ['Retries', k?.retryScheduled],
                  ['Dead letter', k?.deadLetter],
                  ['Taux succès', k?.successRate == null ? '—' : `${Math.round(k.successRate * 100)}%`],
                  ['p95 ms', k?.p95DurationMs ?? '—'],
                  ['Rate limit', k?.rateLimited],
                  ['Alertes', k?.openAlerts],
                  ['Incidents', k?.openIncidents],
                ].map(([label, value]) => (
                  <div key={label} className="mon-kpi">
                    <div className="mon-kpi__value">{value ?? '—'}</div>
                    <div className="mon-kpi__label">{label}</div>
                  </div>
                ))}
              </div>

              <div className="mon-grid-2">
                <div className="mon-panel">
                  <h2>Volume</h2>
                  <MiniBars series={d.series?.hourly} />
                </div>
                <div className="mon-panel">
                  <h2>Domaines</h2>
                  <div className="mon-domains">
                    {Object.entries(d.domains || {}).map(([name, stats]) => (
                      <div key={name} className="mon-domain">
                        <strong>{name}</strong>
                        <span>{stats.sent} ok</span>
                        <span className="mon-domain__err">{stats.error} err</span>
                      </div>
                    ))}
                  </div>
                  <h3 className="mon-h3">File Messenger</h3>
                  <p className="mon-muted">
                    {d.queue?.note} — pending {d.queue?.pending ?? '—'}, failed {d.queue?.failed ?? '—'}
                  </p>
                </div>
              </div>

              {(d.recentAlerts?.length > 0 || d.recentIncidents?.length > 0) && (
                <div className="mon-grid-2">
                  <div className="mon-panel">
                    <h2>Alertes récentes</h2>
                    {d.recentAlerts?.length ? (
                      <ul className="mon-list">
                        {d.recentAlerts.map((a) => (
                          <li key={a.id}>
                            <strong>{a.title}</strong> — {a.severity}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="mon-empty">Aucune alerte ouverte.</p>
                    )}
                  </div>
                  <div className="mon-panel">
                    <h2>Incidents</h2>
                    {d.recentIncidents?.length ? (
                      <ul className="mon-list">
                        {d.recentIncidents.map((i) => (
                          <li key={i.id}>
                            <strong>{i.title}</strong> — {i.status}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="mon-empty">Aucun incident.</p>
                    )}
                  </div>
                </div>
              )}
            </>
          )}
        </section>
      )}

      {tab === 'pipeline' && d && (
        <section className="mon-section">
          <div className="mon-pipe">
            {(d.pipeline || []).map((step) => (
              <div key={step.id} className={`mon-pipe__step ${pipeClass(step.status)}`}>
                <div className="mon-pipe__label">{step.label}</div>
                <div className="mon-pipe__count">{step.count ?? '—'}</div>
                <div className="mon-pipe__detail">{step.detail}</div>
                <div className="mon-pipe__status">{step.status}</div>
              </div>
            ))}
          </div>
        </section>
      )}

      {tab === 'events' && (
        <section className="mon-section">
          {events.error ? <ErrorAlert message={events.error} /> : null}
          <div className="mon-split">
            <div className="mon-panel">
              <h2>Événements ({events.total})</h2>
              {events.loading ? <p className="mon-muted">Chargement…</p> : null}
              <table className="mon-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Statut</th>
                    <th>Org</th>
                    <th>Durée</th>
                  </tr>
                </thead>
                <tbody>
                  {events.items.map((ev) => (
                    <tr key={ev.id} onClick={() => openEvent(ev.id)} className="mon-table__row">
                      <td>{ev.id}</td>
                      <td>{ev.status}</td>
                      <td>{ev.webhook?.organizationName || '—'}</td>
                      <td>{ev.durationMs ?? '—'} ms</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="mon-panel">
              <h2>Détail</h2>
              {!selectedEvent ? <p className="mon-empty">Sélectionnez un événement.</p> : (
                <div className="mon-detail">
                  <p><strong>Correlation</strong> {selectedEvent.correlationId || '—'}</p>
                  <p><strong>Statut</strong> {selectedEvent.status}</p>
                  <p><strong>Erreur</strong> {selectedEvent.errorDetail || '—'}</p>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={async () => {
                      await apiPostJson(`/api/admin/monitoring/events/${selectedEvent.id}/retry`, { body: '{}' });
                      openEvent(selectedEvent.id);
                      loadEvents(events.page);
                    }}
                  >
                    Relancer les actions retryables
                  </button>
                  <h3 className="mon-h3">Actions</h3>
                  <ul className="mon-list">
                    {(selectedEvent.actions || []).map((a) => (
                      <li key={a.id}>{a.actionType} — {a.status} (tentative {a.attempt})</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </div>
        </section>
      )}

      {tab === 'alerts' && (
        <section className="mon-section mon-panel">
          {alerts.error ? <ErrorAlert message={alerts.error} /> : null}
          <table className="mon-table">
            <thead>
              <tr>
                <th>Sévérité</th>
                <th>Titre</th>
                <th>Statut</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {alerts.items.map((a) => (
                <tr key={a.id}>
                  <td>{a.severity}</td>
                  <td>
                    <strong>{a.title}</strong>
                    <div className="mon-muted">{a.message}</div>
                  </td>
                  <td>{a.status}</td>
                  <td>
                    {a.status === 'open' ? (
                      <button type="button" className="btn btn-secondary" onClick={() => ackAlert(a.id)}>
                        Accuser
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!alerts.items.length && !alerts.loading ? <p className="mon-empty">Aucune alerte.</p> : null}
        </section>
      )}

      {tab === 'incidents' && (
        <section className="mon-section mon-panel">
          {incidents.error ? <ErrorAlert message={incidents.error} /> : null}
          <ul className="mon-list">
            {incidents.items.map((i) => (
              <li key={i.id} className="mon-incident">
                <div>
                  <strong>{i.title}</strong>
                  <div className="mon-muted">{i.summary}</div>
                </div>
                <span>{i.status}</span>
                {i.status === 'open' ? (
                  <button type="button" className="btn btn-secondary" onClick={() => resolveIncident(i.id)}>
                    Résoudre
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
          {!incidents.items.length && !incidents.loading ? <p className="mon-empty">Aucun incident.</p> : null}
        </section>
      )}

      {tab === 'costs' && (
        <section className="mon-section">
          {costs.error ? <ErrorAlert message={costs.error} /> : null}
          <div className="mon-grid-2">
            <div className="mon-panel">
              <h2>Estimation</h2>
              {!costs.data?.configured ? (
                <p className="mon-empty">Aucun tarif saisi — les coûts ne sont pas inventés.</p>
              ) : (
                <p className="mon-kpi__value">{formatCents(costs.data.totalCents, costs.data.entries?.[0]?.currency)}</p>
              )}
              <h3 className="mon-h3">Règles</h3>
              <ul className="mon-list">
                {(costs.data?.pricingRules || []).map((r) => (
                  <li key={r.id}>
                    {r.label} — {r.channel}/{r.provider} — {formatCents(r.unitCostCents, r.currency)} / {r.unit}
                    {!r.active ? ' (inactive)' : ''}
                  </li>
                ))}
              </ul>
            </div>
            <div className="mon-panel">
              <h2>Ajouter un tarif</h2>
              <form className="mon-form" onSubmit={createPrice}>
                <label>
                  Canal
                  <input value={priceForm.channel} onChange={(e) => setPriceForm({ ...priceForm, channel: e.target.value })} />
                </label>
                <label>
                  Fournisseur
                  <input value={priceForm.provider} onChange={(e) => setPriceForm({ ...priceForm, provider: e.target.value })} />
                </label>
                <label>
                  Libellé
                  <input value={priceForm.label} onChange={(e) => setPriceForm({ ...priceForm, label: e.target.value })} required />
                </label>
                <label>
                  Coût unitaire (centimes)
                  <input
                    type="number"
                    value={priceForm.unitCostCents}
                    onChange={(e) => setPriceForm({ ...priceForm, unitCostCents: e.target.value })}
                  />
                </label>
                <button type="submit" className="btn btn-primary">Enregistrer</button>
              </form>
            </div>
          </div>
        </section>
      )}

      {tab === 'accounts' && (
        <section className="mon-section mon-panel">
          {accounts.error ? <ErrorAlert message={accounts.error} /> : null}
          <table className="mon-table">
            <thead>
              <tr>
                <th>Organisation</th>
                <th>Plan</th>
                <th>Reçus 24h</th>
                <th>Erreurs 24h</th>
              </tr>
            </thead>
            <tbody>
              {accounts.items.map((a) => (
                <tr key={a.id}>
                  <td>{a.name}</td>
                  <td>{a.plan}</td>
                  <td>{a.received24h}</td>
                  <td>{a.errors24h}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {tab === 'settings' && (
        <section className="mon-section mon-panel">
          <h2>Réglages monitoring (JSON)</h2>
          <p className="mon-muted">Ex. health_thresholds, retry.maxAttempts</p>
          <form onSubmit={saveSettings}>
            <textarea className="mon-textarea" rows={16} value={settingsText} onChange={(e) => setSettingsText(e.target.value)} />
            <div className="mon-form-actions">
              <button type="submit" className="btn btn-primary">Enregistrer</button>
              {settingsMsg ? <span className="mon-muted">{settingsMsg}</span> : null}
            </div>
          </form>
        </section>
      )}
    </div>
  );
}
