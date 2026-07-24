import { useCallback, useEffect, useState } from 'react';
import ErrorAlert from '../components/ui/ErrorAlert.jsx';
import Tabs from '../components/ui/Tabs.jsx';
import { apiJsonInit, apiPostJson, parseJson } from '../lib/http.js';
import './monitoring.css';

const TABS = [
  { id: 'overview', label: 'Vue d’ensemble' },
  { id: 'events', label: 'Événements' },
  { id: 'consumption', label: 'Consommation' },
  { id: 'alerts', label: 'Alertes' },
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

export default function ClientMonitoring() {
  const [tab, setTab] = useState('overview');
  const [period, setPeriod] = useState('24h');
  const [overview, setOverview] = useState({ loading: true, error: '', data: null });
  const [events, setEvents] = useState({ loading: false, error: '', items: [], total: 0 });
  const [selected, setSelected] = useState(null);
  const [consumption, setConsumption] = useState({ loading: false, error: '', data: null });
  const [alerts, setAlerts] = useState({ loading: false, error: '', items: [] });

  const loadOverview = useCallback(async () => {
    setOverview((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch(`/api/monitoring/overview?period=${encodeURIComponent(period)}`, apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Chargement impossible');
      setOverview({ loading: false, error: '', data });
    } catch (e) {
      setOverview({ loading: false, error: e.message || 'Erreur', data: null });
    }
  }, [period]);

  useEffect(() => {
    loadOverview();
  }, [loadOverview]);

  useEffect(() => {
    if (tab === 'events') loadEvents();
    if (tab === 'consumption') loadConsumption();
    if (tab === 'alerts') loadAlerts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab]);

  async function loadEvents() {
    setEvents((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/monitoring/events?limit=40', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setEvents({ loading: false, error: '', items: data.items || [], total: data.total || 0 });
    } catch (e) {
      setEvents((s) => ({ ...s, loading: false, error: e.message }));
    }
  }

  async function openEvent(id) {
    const res = await fetch(`/api/monitoring/events/${id}`, apiJsonInit());
    const data = await parseJson(res);
    if (res.ok) setSelected(data);
  }

  async function loadConsumption() {
    setConsumption((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/monitoring/consumption', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setConsumption({ loading: false, error: '', data });
    } catch (e) {
      setConsumption({ loading: false, error: e.message, data: null });
    }
  }

  async function loadAlerts() {
    setAlerts((s) => ({ ...s, loading: true, error: '' }));
    try {
      const res = await fetch('/api/monitoring/alerts?limit=40', apiJsonInit());
      const data = await parseJson(res);
      if (!res.ok) throw new Error(data?.error || 'Erreur');
      setAlerts({ loading: false, error: '', items: data.items || [] });
    } catch (e) {
      setAlerts({ loading: false, error: e.message, items: [] });
    }
  }

  const d = overview.data;
  const k = d?.kpis;
  const sub = consumption.data?.subscription;

  return (
    <div className="mon-page">
      <header className="mon-page__head">
        <div>
          <h1 className="mon-page__title">Monitoring</h1>
          <p className="mon-page__sub">Santé de vos workflows — scoped à votre organisation</p>
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

      {tab === 'overview' && d && (
        <section className="mon-section">
          <div className={`mon-health ${healthClass(d.health?.status)}`}>
            <div className="mon-health__score">{d.health?.score ?? '—'}</div>
            <div>
              <div className="mon-health__label">Score de santé</div>
              <div className="mon-health__status">{d.health?.status}</div>
            </div>
          </div>
          <div className="mon-kpi-grid">
            {[
              ['Reçus', k?.received],
              ['Succès', k?.success],
              ['Erreurs', k?.error],
              ['Retries', k?.retryScheduled],
              ['Taux succès', k?.successRate == null ? '—' : `${Math.round(k.successRate * 100)}%`],
              ['p95 ms', k?.p95DurationMs ?? '—'],
            ].map(([label, value]) => (
              <div key={label} className="mon-kpi">
                <div className="mon-kpi__value">{value ?? '—'}</div>
                <div className="mon-kpi__label">{label}</div>
              </div>
            ))}
          </div>
          <div className="mon-pipe">
            {(d.pipeline || []).map((step) => (
              <div key={step.id} className={`mon-pipe__step ${pipeClass(step.status)}`}>
                <div className="mon-pipe__label">{step.label}</div>
                <div className="mon-pipe__count">{step.count ?? '—'}</div>
                <div className="mon-pipe__detail">{step.detail}</div>
              </div>
            ))}
          </div>
          {!k?.received ? <p className="mon-empty">Historique insuffisant sur cette période.</p> : null}
        </section>
      )}

      {tab === 'events' && (
        <section className="mon-section">
          {events.error ? <ErrorAlert message={events.error} /> : null}
          <div className="mon-split">
            <div className="mon-panel">
              <h2>Événements ({events.total})</h2>
              <table className="mon-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Workflow</th>
                    <th>Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {events.items.map((ev) => (
                    <tr key={ev.id} className="mon-table__row" onClick={() => openEvent(ev.id)}>
                      <td>{ev.id}</td>
                      <td>{ev.webhook?.name || '—'}</td>
                      <td>{ev.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="mon-panel">
              <h2>Détail</h2>
              {!selected ? <p className="mon-empty">Sélectionnez un événement.</p> : (
                <>
                  <p>Correlation : {selected.correlationId || '—'}</p>
                  <p>{selected.errorDetail || 'OK'}</p>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={async () => {
                      await apiPostJson(`/api/monitoring/events/${selected.id}/retry`, { body: '{}' });
                      openEvent(selected.id);
                    }}
                  >
                    Relancer
                  </button>
                  <ul className="mon-list">
                    {(selected.actions || []).map((a) => (
                      <li key={a.id}>{a.actionType} — {a.status}</li>
                    ))}
                  </ul>
                </>
              )}
            </div>
          </div>
        </section>
      )}

      {tab === 'consumption' && (
        <section className="mon-section mon-panel">
          {consumption.error ? <ErrorAlert message={consumption.error} /> : null}
          {sub ? (
            <div className="mon-kpi-grid">
              <div className="mon-kpi">
                <div className="mon-kpi__value">{sub.eventsConsumed ?? '—'}</div>
                <div className="mon-kpi__label">Événements consommés</div>
              </div>
              <div className="mon-kpi">
                <div className="mon-kpi__value">{sub.eventsAllowance ?? '—'}</div>
                <div className="mon-kpi__label">Quota inclus</div>
              </div>
              <div className="mon-kpi">
                <div className="mon-kpi__value">{sub.eventsRemaining ?? '—'}</div>
                <div className="mon-kpi__label">Restant</div>
              </div>
              <div className="mon-kpi">
                <div className="mon-kpi__value">{sub.plan ?? sub.subscriptionPlan ?? '—'}</div>
                <div className="mon-kpi__label">Forfait</div>
              </div>
            </div>
          ) : (
            <p className="mon-muted">{consumption.loading ? 'Chargement…' : '—'}</p>
          )}
        </section>
      )}

      {tab === 'alerts' && (
        <section className="mon-section mon-panel">
          {alerts.error ? <ErrorAlert message={alerts.error} /> : null}
          {!alerts.items.length ? <p className="mon-empty">Aucune alerte pour votre organisation.</p> : (
            <ul className="mon-list">
              {alerts.items.map((a) => (
                <li key={a.id}>
                  <strong>{a.title}</strong> — {a.severity}
                  <div className="mon-muted">{a.message}</div>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </div>
  );
}
