import { useCallback, useEffect, useMemo, useState } from 'react';
import { parseJson } from '../lib/http.js';

function formatMonthLabel(iso) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  } catch {
    return '—';
  }
}

export default function OrganizationBilling({ user, onSessionRefresh }) {
  const orgId = user.organization?.id;
  const isAdmin = user.roles.includes('ROLE_ADMIN');

  const [activeTab, setActiveTab] = useState('identity');
  const [orgDetail, setOrgDetail] = useState(null);
  const [usage, setUsage] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [plans, setPlans] = useState([]);
  const [packDefs, setPackDefs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [billingMsg, setBillingMsg] = useState('');
  const [upgradeBusy, setUpgradeBusy] = useState(false);
  const [upgradeMsg, setUpgradeMsg] = useState('');
  const [pricingNote, setPricingNote] = useState('');

  const [name, setName] = useState('');
  const [bill1, setBill1] = useState('');
  const [bill2, setBill2] = useState('');
  const [billPostal, setBillPostal] = useState('');
  const [billCity, setBillCity] = useState('');
  const [billCountry, setBillCountry] = useState('');

  const sub = user.subscription;
  const canBuy = user.organization && (user.roles.includes('ROLE_MANAGER') || isAdmin);

  const load = useCallback(async () => {
    if (orgId == null) return;
    setLoading(true);
    setError('');
    setPricingNote('');
    try {
      const [rOrg, rUsage, rInv, rPlans] = await Promise.all([
        fetch(`/api/organizations/${orgId}`, { credentials: 'include' }),
        fetch(`/api/organizations/${orgId}/usage`, { credentials: 'include' }),
        fetch(`/api/organizations/${orgId}/invoices`, { credentials: 'include' }),
        fetch('/api/subscription/plans'),
      ]);
      const dOrg = await parseJson(rOrg);
      const dUsage = await parseJson(rUsage);
      const dInv = await parseJson(rInv);
      const dPlans = await parseJson(rPlans);
      if (!rOrg.ok) {
        setError(dOrg?.error ?? 'Organisation introuvable');
        setOrgDetail(null);
        return;
      }
      setOrgDetail(dOrg);
      if (rUsage.ok && dUsage) setUsage(dUsage);
      if (rInv.ok && Array.isArray(dInv)) setInvoices(dInv);
      if (rPlans.ok && dPlans?.plans) {
        setPlans(dPlans.plans);
        if (dPlans.eventPacks) setPackDefs(dPlans.eventPacks);
        if (typeof dPlans.pricingNote === 'string') setPricingNote(dPlans.pricingNote);
      }
    } catch {
      setError('Erreur réseau');
    } finally {
      setLoading(false);
    }
  }, [orgId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (!orgDetail) return;
    setName(orgDetail.name ?? '');
    const b = orgDetail.billing ?? {};
    setBill1(b.line1 ?? '');
    setBill2(b.line2 ?? '');
    setBillPostal(b.postalCode ?? '');
    setBillCity(b.city ?? '');
    setBillCountry(b.country ?? '');
  }, [orgDetail]);

  const relevantPacks = useMemo(
    () => (sub?.plan != null ? packDefs.filter((p) => p.forPlan === sub.plan && sub.allowEventOverage) : []),
    [packDefs, sub],
  );

  const patchSubscription = async (body) => {
    if (!orgId) return false;
    setUpgradeBusy(true);
    setUpgradeMsg('');
    try {
      const res = await fetch(`/api/organizations/${orgId}/subscription`, {
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
      await load();
      setUpgradeMsg('Mise à jour enregistrée (simulation — branchez Stripe pour les paiements réels).');
      return true;
    } catch {
      setUpgradeMsg('Erreur réseau');
      return false;
    } finally {
      setUpgradeBusy(false);
    }
  };

  const saveOrg = async (e) => {
    e.preventDefault();
    if (orgId == null) return;
    setSaving(true);
    setBillingMsg('');
    setError('');
    try {
      const res = await fetch(`/api/organizations/${orgId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          name: name.trim(),
          billingLine1: bill1.trim() || null,
          billingLine2: bill2.trim() || null,
          billingPostalCode: billPostal.trim() || null,
          billingCity: billCity.trim() || null,
          billingCountry: billCountry.trim() || null,
        }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') {
          setBillingMsg(Object.values(data.fields).join(' '));
        } else {
          setBillingMsg(data?.error ?? 'Enregistrement impossible');
        }
        return;
      }
      setOrgDetail(data);
      setBillingMsg('Modifications enregistrées.');
      await onSessionRefresh?.();
    } catch {
      setBillingMsg('Erreur réseau');
    } finally {
      setSaving(false);
    }
  };

  if (orgId == null) {
    return (
      <div className="users-shell org-billing-page">
        <header className="users-hero users-hero--minimal">
          <div className="users-hero-text">
            <h1 className="users-hero-title">
              <i className="fa-solid fa-file-invoice-dollar" aria-hidden />
              <span>Organisation &amp; facturation</span>
            </h1>
          </div>
        </header>
        <div className="content-card">
          <p className="muted">Aucune organisation rattachée.</p>
        </div>
      </div>
    );
  }

  if (loading && !orgDetail) {
    return (
      <div className="users-shell org-billing-page">
        <header className="users-hero users-hero--minimal">
          <div className="users-hero-text">
            <h1 className="users-hero-title">
              <i className="fa-solid fa-file-invoice-dollar" aria-hidden />
              <span>Organisation &amp; facturation</span>
            </h1>
            <p className="users-hero-sub muted org-billing-header-intro">
              Onglets : coordonnées, offres, consommation et factures.
            </p>
          </div>
        </header>
        <div className="content-card">
          <p className="muted">Chargement…</p>
        </div>
      </div>
    );
  }

  return (
    <div className="users-shell org-billing-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-file-invoice-dollar" aria-hidden />
            <span>Organisation &amp; facturation</span>
          </h1>
          <p className="users-hero-sub muted org-billing-header-intro">
            Onglets : coordonnées, offres, consommation et factures.
          </p>
        </div>
      </header>

      <div className="content-card org-billing-inner">
      {error ? <p className="error">{error}</p> : null}

      <div className="users-tabs org-billing-tabs" role="tablist" aria-label="Sections organisation">
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'identity'}
          className={`users-tab${activeTab === 'identity' ? ' active' : ''}`}
          onClick={() => setActiveTab('identity')}
        >
          Organisation
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'plans'}
          className={`users-tab${activeTab === 'plans' ? ' active' : ''}`}
          onClick={() => setActiveTab('plans')}
        >
          Forfaits &amp; achats
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'usage'}
          className={`users-tab${activeTab === 'usage' ? ' active' : ''}`}
          onClick={() => setActiveTab('usage')}
        >
          Consommation
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'invoices'}
          className={`users-tab${activeTab === 'invoices' ? ' active' : ''}`}
          onClick={() => setActiveTab('invoices')}
        >
          Factures PDF
        </button>
      </div>

      {activeTab === 'identity' ? (
        <div className="users-tab-panel org-billing-tab-panel" role="tabpanel">
          <form className="org-form org-billing-form" onSubmit={(e) => void saveOrg(e)}>
            <div className="org-billing-form-block">
              <h4 className="org-billing-block-title">Raison sociale</h4>
              <label className="field org-billing-field-full">
                <span>Nom affiché</span>
                <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={180} />
              </label>
            </div>

            <div className="org-billing-address-card">
              <div className="org-billing-address-card-head">
                <h4 className="org-billing-block-title">Adresse de facturation</h4>
                <p className="muted small org-billing-address-hint">
                  Figurera sur vos factures. Laissez vide si non applicable pour l’instant.
                </p>
              </div>
              <div className="org-billing-address-fields">
                <label className="field org-billing-field-full">
                  <span>Rue, voie — ligne 1</span>
                  <input
                    value={bill1}
                    onChange={(e) => setBill1(e.target.value)}
                    maxLength={255}
                    autoComplete="address-line1"
                    placeholder="Ex. 12 rue des Artisans"
                  />
                </label>
                <label className="field org-billing-field-full">
                  <span>Complément (bât., étage…)</span>
                  <input
                    value={bill2}
                    onChange={(e) => setBill2(e.target.value)}
                    maxLength={255}
                    autoComplete="address-line2"
                    placeholder="Optionnel"
                  />
                </label>
                <div className="org-billing-address-row-loc">
                  <label className="field">
                    <span>Code postal</span>
                    <input
                      value={billPostal}
                      onChange={(e) => setBillPostal(e.target.value)}
                      maxLength={32}
                      autoComplete="postal-code"
                      inputMode="numeric"
                    />
                  </label>
                  <label className="field org-billing-field-grow">
                    <span>Ville</span>
                    <input value={billCity} onChange={(e) => setBillCity(e.target.value)} maxLength={128} autoComplete="address-level2" />
                  </label>
                  <label className="field org-billing-field-country">
                    <span>Pays</span>
                    <input
                      value={billCountry}
                      onChange={(e) => setBillCountry(e.target.value.toUpperCase())}
                      maxLength={2}
                      placeholder="FR"
                      autoComplete="country"
                      title="Code ISO 3166-1 alpha-2 (2 lettres)"
                      className="org-billing-input-country"
                    />
                  </label>
                </div>
              </div>
            </div>

            {billingMsg ? (
              <p className={billingMsg.toLowerCase().includes('enregistrées') ? 'muted' : 'error'}>{billingMsg}</p>
            ) : null}
            <div className="org-billing-actions">
              <button type="submit" className="btn" disabled={saving}>
                {saving ? '…' : 'Enregistrer les modifications'}
              </button>
            </div>
          </form>
        </div>
      ) : null}

      {activeTab === 'plans' ? (
        <div className="users-tab-panel org-billing-tab-panel" role="tabpanel">
          <p className="muted small org-billing-tab-intro">
            Trois offres : comparez les quotas et souscrivez (simulation tant que Stripe n’est pas branché).
            {pricingNote ? (
              <>
                {' '}
                <strong>{pricingNote}</strong>
              </>
            ) : null}
          </p>
          <div className="org-billing-plans">
            {plans.map((p) => {
              const current = sub?.plan === p.id;
              return (
                <article key={p.id} className={`org-billing-plan-card${current ? ' current' : ''}`}>
                  <h4>{p.label}</h4>
                  <p className="org-billing-price">
                    {p.priceMonthlyEur === 0 ? 'Gratuit' : `${p.priceMonthlyEur} € HT / mois`}
                  </p>
                  <ul className="org-billing-plan-feats">
                    <li>{p.includedEvents?.toLocaleString('fr-FR') ?? '—'} événements inclus</li>
                    <li>{p.maxWebhooks == null ? 'Webhooks illimités' : `Jusqu’à ${p.maxWebhooks} webhook(s)`}</li>
                  </ul>
                  <p className="muted small">{p.description}</p>
                  {canBuy && !current && p.id !== 'free' ? (
                    <button
                      type="button"
                      className="btn secondary small"
                      disabled={upgradeBusy}
                      onClick={() => void patchSubscription({ plan: p.id })}
                    >
                      Choisir ce forfait
                    </button>
                  ) : null}
                  {current ? <span className="badge ok">Forfait actuel</span> : null}
                </article>
              );
            })}
          </div>
          {canBuy && relevantPacks.length > 0 ? (
            <div className="org-billing-packs">
              <span className="muted small">Packs d’événements supplémentaires :</span>
              <div className="dash-upgrade-row">
                {relevantPacks.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    className="btn secondary small"
                    disabled={upgradeBusy}
                    title={p.label}
                    onClick={() => void patchSubscription({ purchaseEventPack: p.id })}
                  >
                    +{p.eventsAdded.toLocaleString('fr-FR')} — {p.priceEur} € HT
                  </button>
                ))}
              </div>
            </div>
          ) : null}
          {upgradeMsg ? <p className="muted small">{upgradeMsg}</p> : null}
        </div>
      ) : null}

      {activeTab === 'usage' ? (
        <div className="users-tab-panel org-billing-tab-panel" role="tabpanel">
          {usage ? (
            <>
              <h4 className="org-billing-block-title" style={{ marginTop: 0 }}>
                Indicateurs courants
              </h4>
              <p className="muted small org-billing-tab-intro">
                Quota global = unités consommées côté abonnement (chaque action exécutée d’un workflow). Les totaux par
                mois (cartes « mois en cours », « mois précédent » et ligne repliée « Détail par mois ») viennent du{' '}
                <strong>compteur mensuel d’événements quota</strong> (aligné sur la consommation du forfait). Les tableaux
                par projet / par workflow comptent les <strong>réceptions</strong> (lignes de journal : un envoi = une
                ligne).
              </p>
              <div className="org-billing-usage">
                <div className="org-billing-usage-card">
                  <span className="muted small">Forfait</span>
                  <p className="org-billing-usage-stat" style={{ fontSize: '1.15rem' }}>
                    {usage.currentIndicators?.planLabel ?? '—'}
                  </p>
                  <p className="muted small">offre souscrite</p>
                </div>
                <div className="org-billing-usage-card">
                  <span className="muted small">Workflows configurés</span>
                  <p className="org-billing-usage-stat">
                    {usage.currentIndicators?.webhookCount?.toLocaleString('fr-FR') ?? '0'}
                    {usage.currentIndicators?.maxWebhooks != null
                      ? ` / ${usage.currentIndicators.maxWebhooks.toLocaleString('fr-FR')}`
                      : ''}
                  </p>
                  <p className="muted small">limite forfait si indiquée</p>
                </div>
                <div className="org-billing-usage-card">
                  <span className="muted small">Quota événements (global)</span>
                  <p className="org-billing-usage-stat">
                    {usage.currentIndicators?.eventsConsumedTotal?.toLocaleString('fr-FR') ??
                      usage.quota.eventsConsumedTotal?.toLocaleString('fr-FR') ??
                      '0'}{' '}
                    /{' '}
                    {usage.currentIndicators?.eventsAllowance?.toLocaleString('fr-FR') ??
                      usage.quota.eventsAllowance?.toLocaleString('fr-FR') ??
                      '0'}
                  </p>
                  <p className="muted small">
                    restant :{' '}
                    {usage.currentIndicators?.eventsRemaining?.toLocaleString('fr-FR') ?? '—'}
                  </p>
                </div>
                <div className="org-billing-usage-card">
                  <span className="muted small">Mois en cours ({formatMonthLabel(usage.currentMonth.periodStart)})</span>
                  <p className="org-billing-usage-stat">
                    {usage.currentMonth.ingressCount?.toLocaleString('fr-FR') ?? '0'}
                  </p>
                  <p className="muted small">événements quota (compteur mensuel)</p>
                </div>
                <div className="org-billing-usage-card">
                  <span className="muted small">Mois précédent</span>
                  <p className="org-billing-usage-stat">
                    {usage.previousMonth.ingressCount?.toLocaleString('fr-FR') ?? '0'}
                  </p>
                  <p className="muted small">événements quota (compteur mensuel)</p>
                </div>
              </div>

              <h4 className="org-billing-block-title" style={{ marginTop: '1.75rem' }}>
                Mois en cours — par projet
              </h4>
              {(usage.currentMonth.byProject ?? []).length === 0 ? (
                <p className="muted small">Aucune réception sur la période.</p>
              ) : (
                <div className="org-table-wrap">
                  <table className="org-table org-billing-nested-table">
                    <thead>
                      <tr>
                        <th>Projet</th>
                        <th className="numeric">Réceptions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(usage.currentMonth.byProject ?? []).map((row) => (
                        <tr key={row.projectId}>
                          <td>{row.projectName}</td>
                          <td className="numeric">{row.ingressCount?.toLocaleString('fr-FR') ?? '0'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <h4 className="org-billing-block-title" style={{ marginTop: '1.75rem' }}>
                Mois en cours — par workflow
              </h4>
              {(usage.currentMonth.byWebhook ?? []).length === 0 ? (
                <p className="muted small">Aucune réception sur la période.</p>
              ) : (
                <div className="org-table-wrap">
                  <table className="org-table org-billing-nested-table">
                    <thead>
                      <tr>
                        <th>Projet</th>
                        <th>Workflow</th>
                        <th className="numeric">Réceptions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(usage.currentMonth.byWebhook ?? []).map((row) => (
                        <tr key={row.webhookId}>
                          <td>{row.projectName}</td>
                          <td>{row.webhookName}</td>
                          <td className="numeric">{row.ingressCount?.toLocaleString('fr-FR') ?? '0'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <h4 className="org-billing-block-title" style={{ marginTop: '1.75rem' }}>
                Détail par mois
              </h4>
              <p className="muted small">
                Douze périodes glissantes (du mois courant à 11 mois en arrière). Dépliez un mois pour voir le détail
                projet et workflow.
              </p>
              <div className="org-billing-monthly-list">
                {(usage.monthlyHistory ?? []).map((m) => (
                  <details key={m.label ?? m.periodStart} className="org-billing-month-details">
                    <summary className="org-billing-month-summary">
                      <span className="org-billing-month-label">{formatMonthLabel(m.periodStart)}</span>
                      <span className="org-billing-month-total">
                        <strong>{m.ingressCount?.toLocaleString('fr-FR') ?? '0'}</strong>
                        <span className="muted small"> événements quota</span>
                      </span>
                    </summary>
                    <div className="org-billing-month-body">
                      <p className="muted small" style={{ marginTop: '0.5rem' }}>
                        Par projet
                      </p>
                      {(m.byProject ?? []).length === 0 ? (
                        <p className="muted small">—</p>
                      ) : (
                        <div className="org-table-wrap">
                          <table className="org-table org-billing-nested-table">
                            <thead>
                              <tr>
                                <th>Projet</th>
                                <th className="numeric">Réceptions</th>
                              </tr>
                            </thead>
                            <tbody>
                              {(m.byProject ?? []).map((row) => (
                                <tr key={`${m.label}-p-${row.projectId}`}>
                                  <td>{row.projectName}</td>
                                  <td className="numeric">{row.ingressCount?.toLocaleString('fr-FR') ?? '0'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                      <p className="muted small" style={{ marginTop: '0.75rem' }}>
                        Par workflow
                      </p>
                      {(m.byWebhook ?? []).length === 0 ? (
                        <p className="muted small">—</p>
                      ) : (
                        <div className="org-table-wrap">
                          <table className="org-table org-billing-nested-table">
                            <thead>
                              <tr>
                                <th>Projet</th>
                                <th>Workflow</th>
                                <th className="numeric">Réceptions</th>
                              </tr>
                            </thead>
                            <tbody>
                              {(m.byWebhook ?? []).map((row) => (
                                <tr key={`${m.label}-w-${row.webhookId}`}>
                                  <td>{row.projectName}</td>
                                  <td>{row.webhookName}</td>
                                  <td className="numeric">{row.ingressCount?.toLocaleString('fr-FR') ?? '0'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  </details>
                ))}
              </div>
            </>
          ) : (
            <p className="muted">Données d’usage indisponibles.</p>
          )}
        </div>
      ) : null}

      {activeTab === 'invoices' ? (
        <div className="users-tab-panel org-billing-tab-panel" role="tabpanel">
          <p className="muted small org-billing-tab-intro">
            Les PDF s’affichent lorsque l’URL est fournie (Stripe ou enregistrement serveur).
          </p>
          {invoices.length === 0 ? (
            <p className="muted">Aucune facture pour l’instant.</p>
          ) : (
            <div className="org-table-wrap">
              <table className="org-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Référence</th>
                    <th>Libellé</th>
                    <th>Montant</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((inv) => (
                    <tr key={inv.id}>
                      <td className="nowrap muted">{inv.issuedAt ? new Date(inv.issuedAt).toLocaleDateString('fr-FR') : '—'}</td>
                      <td>{inv.reference}</td>
                      <td>{inv.title}</td>
                      <td>{inv.amountEur} €</td>
                      <td className="actions">
                        {inv.pdfUrl ? (
                          <a className="btn secondary small" href={inv.pdfUrl} target="_blank" rel="noopener noreferrer">
                            PDF
                          </a>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : null}
      </div>
    </div>
  );
}
