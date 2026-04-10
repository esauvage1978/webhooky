import { useCallback, useEffect, useMemo, useState } from 'react';
import { parseJson } from '../lib/http.js';

/** Jauge semi-circulaire : événements consommés / enveloppe totale (forfait). */
function MgrEventQuotaGauge({ consumed, allowance, planLabel }) {
  const allowanceNum = Number(allowance) || 0;
  const consumedNum = Math.max(0, Number(consumed) || 0);
  const pct = allowanceNum > 0 ? Math.min(100, (consumedNum / allowanceNum) * 100) : 0;
  const r = 85;
  const c = Math.PI * r;
  const offset = c * (1 - pct / 100);

  return (
    <div className="dash-mgr-gauge-card">
      <h2>Quota événements</h2>
      {planLabel ? <p className="dash-mgr-plan-name">{planLabel}</p> : null}
      <div className="dash-gauge-wrap">
        <svg className="dash-gauge-svg" viewBox="0 0 220 130" aria-hidden="true">
          <path
            d="M 25 120 A 85 85 0 0 0 195 120"
            fill="none"
            stroke="var(--border)"
            strokeWidth="14"
            strokeLinecap="round"
          />
          <path
            d="M 25 120 A 85 85 0 0 0 195 120"
            fill="none"
            stroke="var(--coral, #ff5a36)"
            strokeWidth="14"
            strokeLinecap="round"
            strokeDasharray={c}
            strokeDashoffset={offset}
            style={{ transition: 'stroke-dashoffset 0.45s ease' }}
          />
        </svg>
        <div className="dash-gauge-center">
          <div className="dash-gauge-pct">{Math.round(pct)}%</div>
          <div className="dash-gauge-sub">
            {consumedNum.toLocaleString('fr-FR')} / {allowanceNum.toLocaleString('fr-FR')}
          </div>
        </div>
      </div>
      <p className="dash-mgr-gauge-legend">
        Utilisation du quota — une exécution en échec peut tout de même consommer un événement.
      </p>
    </div>
  );
}

export default function DashboardHome({ user, onNavigate, onSessionRefresh, onOpenWorkflow }) {
  const roles = Array.isArray(user.roles) ? user.roles : [];
  const isAdmin = roles.includes('ROLE_ADMIN');
  const isManager = roles.includes('ROLE_MANAGER');
  /** Gestionnaire d’organisation hors rôle admin : tableau de bord allégé (compteurs projet / mois). */
  const isManagerOnly = isManager && !isAdmin && !!user.organization;
  const orgId = user.organization?.id ?? null;
  const [orgCount, setOrgCount] = useState(null);
  const [mjCount, setMjCount] = useState(null);
  const [connCount, setConnCount] = useState(null);
  const [webhooks, setWebhooks] = useState(null);
  /** Données /api/organizations/{id}/usage (réceptions par période, par projet, etc.) */
  const [usage, setUsage] = useState(null);
  const [packDefs, setPackDefs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [upgradeBusy, setUpgradeBusy] = useState(false);
  const [upgradeMsg, setUpgradeMsg] = useState('');
  const [recentErrors, setRecentErrors] = useState([]);
  const [recentErrorsLoading, setRecentErrorsLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const rUsagePromise =
        orgId != null
          ? fetch(`/api/organizations/${orgId}/usage`, { credentials: 'include' })
          : Promise.resolve(null);

      const [rOrg, rSc, rWh, rPlans, rUsage] = await Promise.all([
        fetch('/api/organizations', { credentials: 'include' }),
        fetch('/api/service-connections', { credentials: 'include' }),
        fetch('/api/form-webhooks', { credentials: 'include' }),
        fetch('/api/subscription/plans'),
        rUsagePromise,
      ]);
      const dOrg = await parseJson(rOrg);
      const dSc = await parseJson(rSc);
      const dWh = await parseJson(rWh);
      const dPlans = await parseJson(rPlans);
      const dUsage = rUsage ? await parseJson(rUsage) : null;
      setOrgCount(rOrg.ok && Array.isArray(dOrg) ? dOrg.length : 0);
      const connections = rSc.ok && Array.isArray(dSc) ? dSc : [];
      setMjCount(connections.filter((c) => c.type === 'mailjet').length);
      setConnCount(connections.length);
      setWebhooks(rWh.ok && Array.isArray(dWh) ? dWh : []);
      if (rUsage && rUsage.ok && dUsage) setUsage(dUsage);
      else setUsage(null);
      if (rPlans.ok && dPlans?.eventPacks) setPackDefs(dPlans.eventPacks);
    } catch {
      setOrgCount(0);
      setMjCount(0);
      setConnCount(0);
      setWebhooks([]);
      setUsage(null);
    } finally {
      setLoading(false);
    }
  }, [orgId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (!isManagerOnly || !Array.isArray(webhooks) || webhooks.length === 0) {
      setRecentErrors([]);
      setRecentErrorsLoading(false);
      return;
    }
    let cancelled = false;
    setRecentErrorsLoading(true);
    const maxWh = 14;
    const slice = webhooks.slice(0, maxWh);
    void (async () => {
      try {
        const results = await Promise.all(
          slice.map(async (w) => {
            const res = await fetch(`/api/form-webhooks/${w.id}/logs?limit=25`, { credentials: 'include' });
            const data = await parseJson(res);
            if (!res.ok || !Array.isArray(data?.items)) return [];
            return data.items
              .filter((item) => item.status === 'error')
              .map((item) => ({
                ...item,
                webhookId: w.id,
                webhookName: w.name,
              }));
          }),
        );
        if (cancelled) return;
        const flat = results.flat();
        flat.sort((a, b) => {
          const ta = a.receivedAt ? new Date(a.receivedAt).getTime() : 0;
          const tb = b.receivedAt ? new Date(b.receivedAt).getTime() : 0;
          return tb - ta;
        });
        setRecentErrors(flat.slice(0, 12));
      } catch {
        if (!cancelled) setRecentErrors([]);
      } finally {
        if (!cancelled) setRecentErrorsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isManagerOnly, webhooks]);

  const sub = user.subscription;
  const canManageBilling = user.organization && (isManager || isAdmin);

  const patchSubscription = async (body) => {
    if (!user.organization?.id) return false;
    setUpgradeBusy(true);
    setUpgradeMsg('');
    try {
      const res = await fetch(`/api/organizations/${user.organization.id}/subscription`, {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setUpgradeMsg(data?.error ?? 'Mise à jour impossible');
        return false;
      }
      await onSessionRefresh?.();
      setUpgradeMsg('Mise à jour enregistrée (simulation — branchez Stripe pour les paiements réels).');
      return true;
    } catch {
      setUpgradeMsg('Erreur réseau');
      return false;
    } finally {
      setUpgradeBusy(false);
    }
  };

  const buyPack = async (packId) => {
    await patchSubscription({ purchaseEventPack: packId });
  };

  const relevantPacks =
    sub?.plan != null ? packDefs.filter((p) => p.forPlan === sub.plan && sub.allowEventOverage) : [];

  const webhookTableRows = useMemo(() => {
    if (!Array.isArray(webhooks) || webhooks.length === 0) return [];
    return [...webhooks].sort((a, b) => {
      const pa = (a.project?.name ?? '').localeCompare(b.project?.name ?? '', 'fr', { sensitivity: 'base' });
      if (pa !== 0) return pa;
      return a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' });
    });
  }, [webhooks]);

  const currentMonthByProject = usage?.currentMonth?.byProject ?? [];
  const currentMonthIngress = usage?.currentMonth?.ingressCount ?? 0;
  const openWorkflow = onOpenWorkflow ?? (() => {});

  return (
    <div className="users-shell dashboard-home">
      <header className="users-hero users-hero--minimal dashboard-home-hero">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-gauge-high" aria-hidden />
            <span>Bonjour</span>
          </h1>
          <p className="users-hero-sub muted dashboard-home-intro">
            {isManagerOnly
              ? 'Vue synthétique : quota, activité du mois et accès rapides.'
              : 'Indicateurs clés, workflows et raccourcis vers vos outils.'}
          </p>
        </div>
      </header>

      <div className="content-card dashboard-home-card">
        {!isManagerOnly ? (
          <div className="dashboard-cards">
            <article className="dash-card dash-card-accent">
              <h2 className="dash-card-title">Organisations</h2>
              <p className="dash-card-stat">{loading ? '…' : orgCount}</p>
              <p className="dash-card-desc">
                {isAdmin ? 'Structures gérées sur la plateforme.' : 'Votre structure rattachée au compte.'}
              </p>
              {isAdmin ? (
                <button type="button" className="btn btn-card-action" onClick={() => onNavigate('organizations')}>
                  Gérer les organisations
                </button>
              ) : null}
            </article>

            <article className="dash-card dash-card-dark">
              <h2 className="dash-card-title">Intégrations</h2>
              <p className="dash-card-stat">
                {loading ? '…' : `${mjCount ?? 0} Mailjet · ${connCount ?? 0} connect.`}
              </p>
              <p className="dash-card-desc">Mailjet, Slack, SMS, Telegram, HTTP, Pushover…</p>
              <button type="button" className="btn btn-card-action-light" onClick={() => onNavigate('integrations')}>
                Gérer les intégrations
              </button>
            </article>
          </div>
        ) : null}

        {sub && user.organization ? (
          <div
            className={`dashboard-visual-band ${
              sub.blockReason || !sub.webhooksOperational ? 'dashboard-visual-band--warn' : ''
            }`}
          >
            <div className="dashboard-visual-band-head">
              <h2 className="dashboard-visual-band-title">Indicateurs clés</h2>
              <p className="muted small dashboard-visual-band-meta">
                <strong>{sub.planLabel}</strong>
                {' · '}
                {sub.webhookCount ?? 0}
                {sub.maxWebhooks != null ? ` / ${sub.maxWebhooks}` : ''} webhook(s)
                {' · '}
                <span>
                  {sub.eventsConsumed ?? 0} / {sub.eventsAllowance ?? 0} événements
                  {sub.eventsRemaining != null && sub.eventsRemaining >= 0 ? ` (${sub.eventsRemaining} restants)` : null}
                </span>
                {sub.eventsExtraQuota > 0 ? (
                  <span>
                    {' '}
                    — pack +{sub.eventsExtraQuota}
                  </span>
                ) : null}
              </p>
            </div>

            <div className="dash-mgr-top dashboard-visual-kpis-row">
              <MgrEventQuotaGauge
                consumed={sub.eventsConsumed ?? 0}
                allowance={sub.eventsAllowance ?? 0}
                planLabel=""
              />
              <div className="dash-mgr-kpis" role="list">
                <article className="dash-mgr-kpi" role="listitem">
                  <div className="dash-mgr-kpi-icon" aria-hidden>
                    ⚡
                  </div>
                  <p className="dash-mgr-kpi-value">{loading ? '…' : (webhooks?.length ?? 0).toLocaleString('fr-FR')}</p>
                  <p className="dash-mgr-kpi-label">Workflows</p>
                </article>
                <article className="dash-mgr-kpi" role="listitem">
                  <div className="dash-mgr-kpi-icon" aria-hidden>
                    📥
                  </div>
                  <p className="dash-mgr-kpi-value">
                    {loading || usage === null ? '…' : currentMonthIngress.toLocaleString('fr-FR')}
                  </p>
                  <p className="dash-mgr-kpi-label">Réceptions (mois)</p>
                </article>
                <article className="dash-mgr-kpi" role="listitem">
                  <div className="dash-mgr-kpi-icon" aria-hidden>
                    ✓
                  </div>
                  <p className="dash-mgr-kpi-value">
                    {sub?.eventsRemaining != null ? sub.eventsRemaining.toLocaleString('fr-FR') : '—'}
                  </p>
                  <p className="dash-mgr-kpi-label">Événements restants</p>
                </article>
                <article className="dash-mgr-kpi" role="listitem">
                  <div className="dash-mgr-kpi-icon" aria-hidden>
                    ⏱
                  </div>
                  <p className="dash-mgr-kpi-value">{sub?.webhooksOperational === false ? 'Suspendu' : 'OK'}</p>
                  <p className="dash-mgr-kpi-label">Réception webhooks</p>
                </article>
              </div>
            </div>

            {sub.blockReason ? <p className="error small dashboard-visual-alert">{sub.blockReason}</p> : null}
            {!sub.webhooksOperational ? (
              <p className="error small dashboard-visual-alert">
                Les réceptions sur vos webhooks sont refusées (abonnement ou quota).
              </p>
            ) : null}

            {canManageBilling && relevantPacks.length > 0 ? (
              <div className="dashboard-visual-packs">
                <p className="muted small dashboard-visual-packs-intro">
                  Packs d’événements supplémentaires (simulation, prix HT — TVA en sus à la facturation).
                </p>
                <div className="dash-upgrade-row">
                  {relevantPacks.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      className="btn secondary small"
                      disabled={upgradeBusy}
                      title={p.label}
                      onClick={() => void buyPack(p.id)}
                    >
                      +{p.eventsAdded.toLocaleString('fr-FR')} — {p.priceEur} € HT
                    </button>
                  ))}
                </div>
                <p className="muted small dashboard-visual-packs-foot">
                  Paiement réel : <code className="mono">docs/PAIEMENT_STRIPE.md</code>
                </p>
              </div>
            ) : null}

            {upgradeMsg ? <p className="muted small dashboard-visual-upgrade-msg">{upgradeMsg}</p> : null}
          </div>
        ) : null}

        {(isAdmin || user.organization) &&
          (isManagerOnly ? (
          <section className="dashboard-webhooks-section dash-mgr-visual" aria-labelledby="dash-manager-visual-heading">
            <div className="dashboard-webhooks-head">
              <h2 id="dash-manager-visual-heading" className="dashboard-section-title">
                Tableau de bord
              </h2>
              <div className="row" style={{ gap: '0.5rem', flexWrap: 'wrap' }}>
                <button type="button" className="btn secondary small" onClick={() => onNavigate('organizationBilling')}>
                  Organisation &amp; facturation
                </button>
                <button type="button" className="btn small" onClick={() => onNavigate('formWebhooks')}>
                  Tous les workflows
                </button>
              </div>
            </div>

            <div className="dash-mgr-errors">
              <h3>
                <span aria-hidden>⚠</span> Dernières exécutions en erreur
              </h3>
              {recentErrorsLoading ? (
                <p className="muted small">Analyse des journaux sur vos workflows…</p>
              ) : recentErrors.length === 0 ? (
                <p className="muted small">Aucune erreur récente sur les workflows interrogés. Ouvrez les journaux d’un workflow pour l’historique complet.</p>
              ) : (
                <ul className="dash-mgr-errors-list">
                  {recentErrors.map((e) => (
                    <li key={`${e.webhookId}-${e.id}`} className="dash-mgr-error-item">
                      <button
                        type="button"
                        className="btn secondary small"
                        onClick={() => openWorkflow({ kind: 'logs', id: e.webhookId })}
                      >
                        Journaux
                      </button>
                      <div>
                        <strong>{e.webhookName}</strong>
                        <span className="dash-mgr-error-meta">
                          {' '}
                          ·{' '}
                          {e.receivedAt
                            ? new Date(e.receivedAt).toLocaleString('fr-FR', {
                                dateStyle: 'short',
                                timeStyle: 'short',
                              })
                            : '—'}
                        </span>
                      </div>
                      {e.errorDetail ? <p className="dash-mgr-error-msg">{e.errorDetail}</p> : null}
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="dash-mgr-projects-block">
              <h3>Réceptions par projet — mois en cours</h3>
              {loading ? (
                <p className="muted">Chargement des statistiques…</p>
              ) : usage === null ? (
                <p className="muted">Impossible de charger les statistiques d’usage.</p>
              ) : currentMonthByProject.length === 0 ? (
                <p className="muted">
                  Aucune réception ce mois-ci. Les compteurs comptent une ligne par exécution enregistrée sur un workflow.
                </p>
              ) : (
                <div className="dash-project-counters" role="list">
                  {currentMonthByProject.map((row) => (
                    <article key={row.projectId} className="dash-project-counter-card" role="listitem">
                      <h3 className="dash-project-counter-name">{row.projectName}</h3>
                      <p className="dash-project-counter-value">{row.ingressCount?.toLocaleString('fr-FR') ?? '0'}</p>
                      <p className="muted small dash-project-counter-hint">réceptions (mois en cours)</p>
                    </article>
                  ))}
                </div>
              )}
            </div>
          </section>
        ) : (
          <section className="dashboard-webhooks-section" aria-labelledby="dash-webhooks-heading">
            <div className="dashboard-webhooks-head">
              <h2 id="dash-webhooks-heading" className="dashboard-section-title">
                Webhooks formulaires
              </h2>
              <div className="row" style={{ gap: '0.5rem', flexWrap: 'wrap' }}>
                <button type="button" className="btn secondary small" onClick={() => onNavigate('webhookProjects')}>
                  Projets
                </button>
                <button type="button" className="btn secondary small" onClick={() => onNavigate('formWebhooks')}>
                  Gérer les webhooks
                </button>
              </div>
            </div>
            {loading || webhooks === null ? (
              <p className="muted">Chargement des webhooks…</p>
            ) : webhooks.length === 0 ? (
              <p className="muted">
                Aucun webhook pour l’instant. Créez-en un pour recevoir vos formulaires et les envoyer à Mailjet.
              </p>
            ) : (
              <div className="org-table-wrap">
                <table className="org-table" aria-label="Workflows par projet">
                  <thead>
                    <tr>
                      <th>Projet</th>
                      <th>Workflow</th>
                      {isAdmin ? <th>Organisation</th> : null}
                      <th>Événements (journaux)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {webhookTableRows.map((w) => (
                      <tr key={w.id}>
                        <td>{w.project?.name ?? '—'}</td>
                        <td>
                          <button type="button" className="btn secondary small" onClick={() => onNavigate('formWebhooks')}>
                            {w.name}
                          </button>
                        </td>
                        {isAdmin ? <td className="muted small">{w.organization?.name ?? '—'}</td> : null}
                        <td>{w.logsCount ?? 0}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
          ))}
      </div>
    </div>
  );
}

