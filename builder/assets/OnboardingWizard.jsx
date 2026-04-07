import { useCallback, useEffect, useMemo, useState } from 'react';
import SetupOrganization from './SetupOrganization.jsx';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export default function OnboardingWizard({ user, onRefresh }) {
  const step = user.onboarding?.currentStep ?? null;
  const isManager = user.roles.includes('ROLE_MANAGER');
  const [displayName, setDisplayName] = useState(() => user.displayName ?? '');
  const [avatarKey, setAvatarKey] = useState(() => user.avatarKey ?? '');
  const [plans, setPlans] = useState([]);
  const [planChoice, setPlanChoice] = useState('free');
  const [profileError, setProfileError] = useState('');
  const [profileFields, setProfileFields] = useState({});
  const [planError, setPlanError] = useState('');
  const [busy, setBusy] = useState(false);

  const avatarOptions = user.onboarding?.avatarOptions ?? [];

  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (step !== 'plan') return;
      const res = await fetch('/api/subscription/plans');
      const data = await parseJson(res);
      if (!cancelled && res.ok && data?.plans) {
        setPlans(data.plans);
        if (data.plans[0]?.id) setPlanChoice(data.plans[0].id);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [step]);

  const managerStepIndex = useMemo(() => {
    if (!isManager) return 0;
    if (step === 'create_organization') return 1;
    if (step === 'profile') return 2;
    if (step === 'plan') return 3;
    return 0;
  }, [isManager, step]);

  const submitProfile = useCallback(async () => {
    setBusy(true);
    setProfileError('');
    setProfileFields({});
    try {
      const res = await fetch('/api/onboarding/profile', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ displayName: displayName.trim(), avatarKey }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setProfileFields(data.fields);
        setProfileError(data?.error && typeof data.error === 'string' ? data.error : 'Enregistrement impossible');
        return;
      }
      await onRefresh?.();
    } catch {
      setProfileError('Erreur réseau');
    } finally {
      setBusy(false);
    }
  }, [displayName, avatarKey, onRefresh]);

  const submitPlan = useCallback(async () => {
    setBusy(true);
    setPlanError('');
    try {
      const res = await fetch('/api/onboarding/plan', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ plan: planChoice }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setPlanError(data?.error && typeof data.error === 'string' ? data.error : 'Choix impossible');
        return;
      }
      await onRefresh?.();
    } catch {
      setPlanError('Erreur réseau');
    } finally {
      setBusy(false);
    }
  }, [planChoice, onRefresh]);

  if (!step) {
    return null;
  }

  return (
    <div className="onboarding-shell">
      <div className="onboarding-card">
        <header className="onboarding-header">
          <p className="onboarding-brand">
            <span className="onboarding-brand-mark" aria-hidden />
            Webhooky Builders
          </p>
          {isManager ? (
            <p className="onboarding-step-label">
              Étape {managerStepIndex} sur 3 — configuration initiale obligatoire
            </p>
          ) : (
            <p className="onboarding-step-label">Étape 1 sur 1 — finalisez votre profil</p>
          )}
        </header>

        {step === 'create_organization' ? (
          <div className="onboarding-panel">
            <h1 className="onboarding-title">Votre organisation</h1>
            <p className="onboarding-intro">
              Créez la structure pour laquelle vous allez utiliser Webhooky. Vous pourrez inviter des membres ensuite.
            </p>
            <SetupOrganization onSuccess={onRefresh} embeddedInOnboarding />
          </div>
        ) : null}

        {step === 'profile' ? (
          <div className="onboarding-panel">
            <h1 className="onboarding-title">Votre profil</h1>
            <p className="onboarding-intro">
              Choisissez un nom affiché pour vos équipes et un avatar. Ils seront visibles dans l’interface.
            </p>
            <div className="onboarding-form mailjet-form">
              <label className="field">
                <span>Nom d’affichage</span>
                <input
                  value={displayName}
                  onChange={(e) => setDisplayName(e.target.value)}
                  maxLength={120}
                  autoComplete="nickname"
                  required
                  placeholder="Ex. Marie D."
                />
              </label>
              {profileFields.displayName ? <p className="error">{profileFields.displayName}</p> : null}
              <div className="field">
                <span>Avatar</span>
                <div className="onboarding-avatar-grid" role="group" aria-label="Choisir un avatar">
                  {avatarOptions.map((opt) => (
                    <button
                      key={opt.id}
                      type="button"
                      className={`onboarding-avatar-btn onboarding-avatar-btn--${opt.id} ${
                        avatarKey === opt.id ? 'selected' : ''
                      }`}
                      onClick={() => setAvatarKey(opt.id)}
                      title={opt.label}
                      aria-label={opt.label}
                      aria-pressed={avatarKey === opt.id}
                    />
                  ))}
                </div>
                {profileFields.avatarKey ? <p className="error">{profileFields.avatarKey}</p> : null}
              </div>
              {profileError ? <p className="error">{profileError}</p> : null}
              <button type="button" className="btn" disabled={busy || !displayName.trim() || !avatarKey} onClick={() => void submitProfile()}>
                {busy ? 'Enregistrement…' : 'Continuer'}
              </button>
            </div>
          </div>
        ) : null}

        {step === 'plan' ? (
          <div className="onboarding-panel">
            <h1 className="onboarding-title">Forfait de l’organisation</h1>
            <p className="onboarding-intro">
              Sélectionnez l’offre adaptée à votre volume. Vous pourrez la modifier plus tard depuis la facturation.
            </p>
            <div className="onboarding-plans">
              {plans.map((p) => (
                <label
                  key={p.id}
                  className={`onboarding-plan-card ${planChoice === p.id ? 'onboarding-plan-card--selected' : ''}`}
                >
                  <input
                    type="radio"
                    name="onb-plan"
                    value={p.id}
                    checked={planChoice === p.id}
                    onChange={() => setPlanChoice(p.id)}
                  />
                  <strong>{p.label}</strong>
                  <span className="onboarding-plan-desc">{p.description}</span>
                </label>
              ))}
            </div>
            {planError ? <p className="error">{planError}</p> : null}
            <button type="button" className="btn" disabled={busy || plans.length === 0} onClick={() => void submitPlan()}>
              {busy ? 'Validation…' : 'Valider et accéder au tableau de bord'}
            </button>
          </div>
        ) : null}
      </div>
    </div>
  );
}
