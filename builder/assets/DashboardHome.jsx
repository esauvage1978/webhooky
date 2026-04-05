import { useCallback, useEffect, useState } from 'react';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export default function DashboardHome({ user, onNavigate, onSessionRefresh }) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const isManager = user.roles.includes('ROLE_MANAGER');
  const [orgCount, setOrgCount] = useState(null);
  const [mjCount, setMjCount] = useState(null);
  const [webhooks, setWebhooks] = useState(null);
  const [packDefs, setPackDefs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [upgradeBusy, setUpgradeBusy] = useState(false);
  const [upgradeMsg, setUpgradeMsg] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [rOrg, rMj, rWh, rPlans] = await Promise.all([
        fetch('/api/organizations', { credentials: 'include' }),
        fetch('/api/mailjets', { credentials: 'include' }),
        fetch('/api/form-webhooks', { credentials: 'include' }),
        fetch('/api/subscription/plans'),
      ]);
      const dOrg = await parseJson(rOrg);
      const dMj = await parseJson(rMj);
      const dWh = await parseJson(rWh);
      const dPlans = await parseJson(rPlans);
      setOrgCount(rOrg.ok && Array.isArray(dOrg) ? dOrg.length : 0);
      setMjCount(rMj.ok && Array.isArray(dMj) ? dMj.length : 0);
      setWebhooks(rWh.ok && Array.isArray(dWh) ? dWh : []);
      if (rPlans.ok && dPlans?.eventPacks) setPackDefs(dPlans.eventPacks);
    } catch {
      setOrgCount(0);
      setMjCount(0);
      setWebhooks([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

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

  const upgradePlan = async (plan) => {
    await patchSubscription({ plan });
  };

  const buyPack = async (packId) => {
    await patchSubscription({ purchaseEventPack: packId });
  };

  const relevantPacks =
    sub?.plan != null ? packDefs.filter((p) => p.forPlan === sub.plan && sub.allowEventOverage) : [];

  const eventsPct =
    sub?.eventsAllowance > 0 ? Math.min(100, ((sub.eventsConsumed ?? 0) / sub.eventsAllowance) * 100) : 0;

  return (
    <div className="dashboard-home">
      <div className="dashboard-hero">
        <h1 className="dashboard-title">Bonjour</h1>
        <p className="dashboard-subtitle">
          Vue d’ensemble de votre espace <strong>Webhooky</strong> — gérez les organisations, les clés Mailjet et vos
          intégrations.
        </p>
      </div>

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
          <h2 className="dash-card-title">Configurations Mailjet</h2>
          <p className="dash-card-stat">{loading ? '…' : mjCount}</p>
          <p className="dash-card-desc">Paires de clés API enregistrées pour l’envoi e-mail.</p>
          <button type="button" className="btn btn-card-action-light" onClick={() => onNavigate('mailjets')}>
            Ouvrir Mailjet
          </button>
        </article>

        {sub && user.organization ? (
          <article
            className={`dash-card ${sub.blockReason || !sub.webhooksOperational ? 'dash-card-warn' : 'dash-card-muted'}`}
          >
            <h2 className="dash-card-title">Forfait &amp; événements</h2>
            <p className="dash-card-desc">
              <strong>{sub.planLabel}</strong>
            </p>
            <p className="dash-card-stat subtle">
              {sub.webhookCount ?? 0}
              {sub.maxWebhooks != null ? ` / ${sub.maxWebhooks}` : ''} webhook(s)
            </p>

            <div className="dash-events-meter" aria-label="Utilisation du quota d’événements">
              <div className="dash-events-meter-label">
                <span>
                  Événements : <strong>{sub.eventsConsumed ?? 0}</strong> / {sub.eventsAllowance ?? 0}
                </span>
                {sub.eventsExtraQuota > 0 ? (
                  <span className="muted small">
                    (dont {sub.eventsIncluded ?? 0} inclus + {sub.eventsExtraQuota} pack)
                  </span>
                ) : null}
              </div>
              <div className="dash-events-bar">
                <div className="dash-events-bar-fill" style={{ width: `${eventsPct}%` }} />
              </div>
              <p className="muted small">
                {sub.eventsRemaining ?? 0} restant(s)
                {sub.plan === 'free' ? ' — pas de dépassement : upgrade obligatoire au-delà.' : null}
              </p>
            </div>

            {sub.blockReason ? <p className="error small">{sub.blockReason}</p> : null}
            {!sub.webhooksOperational ? (
              <p className="error small">Les réceptions sur vos webhooks sont refusées (abonnement ou quota).</p>
            ) : null}

            {canManageBilling && (sub.plan === 'free' || sub.blockReason) ? (
              <div className="dash-upgrade-row">
                <button
                  type="button"
                  className="btn secondary small"
                  disabled={upgradeBusy}
                  onClick={() => void upgradePlan('starter')}
                >
                  Starter — 9 €/mois (5 000 év.)
                </button>
                <button
                  type="button"
                  className="btn small"
                  disabled={upgradeBusy}
                  onClick={() => void upgradePlan('pro')}
                >
                  Pro — 29 €/mois (50 000 év.)
                </button>
              </div>
            ) : null}

            {canManageBilling && relevantPacks.length > 0 ? (
              <div className="dash-pack-row">
                <span className="muted small">Packs d’événements (simulation) :</span>
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
                      +{p.eventsAdded.toLocaleString()} — {p.priceEur} €
                    </button>
                  ))}
                </div>
              </div>
            ) : null}

            {upgradeMsg ? <p className="muted small">{upgradeMsg}</p> : null}
            <p className="muted small">
              Paiement réel : voir <code className="mono">docs/PAIEMENT_STRIPE.md</code>
            </p>
          </article>
        ) : null}

        <article className="dash-card dash-card-outline">
          <h2 className="dash-card-title">Compte</h2>
          <ul className="dash-list">
            <li>
              <span className="dash-list-label">E-mail</span>
              <span className="dash-list-value">{user.email}</span>
            </li>
            <li>
              <span className="dash-list-label">Rôles</span>
              <span className="dash-list-value">{user.roles.filter((r) => r !== 'ROLE_USER').join(', ') || 'Utilisateur'}</span>
            </li>
            {user.organization ? (
              <li>
                <span className="dash-list-label">Organisation</span>
                <span className="dash-list-value">
                  {user.organization.name}{' '}
                  <small className="dash-id">#{user.organization.id}</small>
                </span>
              </li>
            ) : null}
          </ul>
        </article>
      </div>

      {(isAdmin || user.organization) && (
        <section className="dashboard-webhooks-section" aria-labelledby="dash-webhooks-heading">
          <div className="dashboard-webhooks-head">
            <h2 id="dash-webhooks-heading" className="dashboard-section-title">
              Webhooks formulaires
            </h2>
            <button type="button" className="btn secondary small" onClick={() => onNavigate('formWebhooks')}>
              Gérer les webhooks
            </button>
          </div>
          {loading || webhooks === null ? (
            <p className="muted">Chargement des webhooks…</p>
          ) : webhooks.length === 0 ? (
            <p className="muted">
              Aucun webhook pour l’instant. Créez-en un pour recevoir vos formulaires et les envoyer à Mailjet.
            </p>
          ) : (
            <div className="dashboard-webhook-grid">
              {webhooks.map((w) => (
                <button
                  key={w.id}
                  type="button"
                  className="dash-card dash-card-webhook-mini"
                  onClick={() => onNavigate('formWebhooks')}
                >
                  <span className="dash-card-title">Webhook</span>
                  <span className="dash-webhook-name">{w.name}</span>
                  <span className="dash-webhook-stat" aria-label={`${w.logsCount ?? 0} journaux`}>
                    {w.logsCount ?? 0}
                  </span>
                  <span className="dash-webhook-stat-label">journaux enregistrés</span>
                </button>
              ))}
            </div>
          )}
        </section>
      )}
    </div>
  );
}
