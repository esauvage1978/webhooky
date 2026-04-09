import { useCallback, useEffect, useMemo, useState } from 'react';
import { setAppSessionKnownLoggedIn } from './sessionFetch.js';
import LoginForm from './LoginForm.jsx';
import ForgotPasswordForm from './ForgotPasswordForm.jsx';
import RegisterForm from './RegisterForm.jsx';
import ResetPasswordForm from './ResetPasswordForm.jsx';
import DashboardLayout from './DashboardLayout.jsx';
import DashboardHome from './DashboardHome.jsx';
import Organizations from './Organizations.jsx';
import Integrations from './Integrations.jsx';
import FormWebhooks from './FormWebhooks.jsx';
import WebhookProjects from './WebhookProjects.jsx';
import SetupOrganization from './SetupOrganization.jsx';
import InvitationForm from './InvitationForm.jsx';
import Users from './Users.jsx';
import UsersJournal from './UsersJournal.jsx';
import OrganizationBilling from './OrganizationBilling.jsx';
import OnboardingWizard from './OnboardingWizard.jsx';
import AccountProfile from './AccountProfile.jsx';
import AccountChangePassword from './AccountChangePassword.jsx';

const ORG_SESSION_KEY = 'webhookyOrgSessionOk';
const AUTH_PATHS = ['/inscription', '/mot-de-passe-oublie', '/reinitialisation-mot-de-passe', '/invitation'];

function normalizePath(pathname) {
  return (pathname || '/').replace(/\/$/, '') || '/';
}

/** Préfixe Symfony (`app.request.basePath`), ex. `/webhooky/public` sous WAMP. */
function getWebhookyBasePath() {
  if (typeof window === 'undefined') return '';
  const b = window.__WEBHOOKY_BASE_PATH__;
  if (typeof b !== 'string' || b === '') return '';
  return b.replace(/\/$/, '');
}

/** @param {string} path */
function absoluteAppPath(path) {
  const base = getWebhookyBasePath();
  const rel = path.startsWith('/') ? path : `/${path}`;
  if (!base) return rel;
  if (rel === '/') return base;
  return `${base}${rel}`;
}

/**
 * Chemins /workflows avec préfixe d’appli (ex. /public/workflows/5/edit).
 * Ne pas ancrer en `^/workflows` : sinon sous-répertoire → null → l’UI traite la route comme « liste » et vide l’éditeur.
 *
 * @returns {{ kind: 'list' } | { kind: 'detail'; id: number } | { kind: 'edit'; id: number } | { kind: 'logs'; id: number } | null}
 */
function parseWebhooksRoute(pathname) {
  const p = normalizePath(pathname);
  if (p === '/workflows' || /\/workflows$/.test(p)) return { kind: 'list' };
  const editMatch = p.match(/\/workflows\/(\d+)\/edit$/);
  if (editMatch) return { kind: 'edit', id: parseInt(editMatch[1], 10) };
  const logsMatch = p.match(/\/workflows\/(\d+)\/logs$/);
  if (logsMatch) return { kind: 'logs', id: parseInt(logsMatch[1], 10) };
  const detailMatch = p.match(/\/workflows\/(\d+)$/);
  if (detailMatch) return { kind: 'detail', id: parseInt(detailMatch[1], 10) };
  return null;
}

/** @param {{ kind: 'list' } | { kind: 'detail'; id: number } | { kind: 'edit'; id: number } | { kind: 'logs'; id: number }} route */
function pathForWebhooksRoute(route) {
  const root = absoluteAppPath('/workflows');
  if (!route || route.kind === 'list') return root;
  if (route.kind === 'detail') return `${root}/${route.id}`;
  if (route.kind === 'edit') return `${root}/${route.id}/edit`;
  if (route.kind === 'logs') return `${root}/${route.id}/logs`;
  return root;
}

/** Ancienne URL publique « …/webhooks » → « …/workflows » (avec ou sans préfixe d’appli). */
function replaceLegacyWebhooksUrlIfNeeded() {
  const p = normalizePath(window.location.pathname);
  if (/(^|\/)webhooks(\/|$)/.test(p)) {
    const next = normalizePath(p.replace(/(^|\/)webhooks(?=\/|$)/g, '$1workflows'));
    window.history.replaceState({}, '', next);
    return next;
  }
  return p;
}

/** @param {string} pathname */
function pathToNavId(pathname) {
  const p = normalizePath(pathname);
  if (AUTH_PATHS.includes(p)) return null;
  if (p === '/' || p === '/dashboard') return 'dashboard';
  if (/(^|\/)(workflows|webhooks)(\/|$)/.test(p)) {
    return 'formWebhooks';
  }
  if (/(^|\/)users\/journal$/.test(p) || /(^|\/)utilisateurs\/journal$/.test(p)) {
    return 'usersJournal';
  }
  if (/(^|\/)projets-workflows$/.test(p)) {
    return 'webhookProjects';
  }
  const seg = p.slice(1).split('/')[0] || '';
  switch (seg) {
    case 'workflows':
    case 'webhooks':
      return 'formWebhooks';
    case 'mailjet':
    case 'mailjets':
    case 'integrations':
      return 'integrations';
    case 'organizations':
    case 'organisations':
      return 'organizations';
    case 'users':
    case 'utilisateurs':
      return 'users';
    case 'mon-organisation':
      return 'setupOrganization';
    case 'facturation':
      return 'organizationBilling';
    case 'mon-profil':
      return 'accountProfile';
    case 'changer-mot-de-passe':
      return 'changePassword';
    default:
      return null;
  }
}

/** @param {string} navId */
function navIdToPath(navId) {
  let rel = '/';
  switch (navId) {
    case 'dashboard':
      rel = '/';
      break;
    case 'formWebhooks':
      rel = '/workflows';
      break;
    case 'webhookProjects':
      rel = '/projets-workflows';
      break;
    case 'integrations':
      rel = '/integrations';
      break;
    case 'organizations':
      rel = '/organizations';
      break;
    case 'users':
      rel = '/users';
      break;
    case 'usersJournal':
      rel = '/users/journal';
      break;
    case 'setupOrganization':
      rel = '/mon-organisation';
      break;
    case 'organizationBilling':
      rel = '/facturation';
      break;
    case 'accountProfile':
      rel = '/mon-profil';
      break;
    case 'changePassword':
      rel = '/changer-mot-de-passe';
      break;
    default:
      rel = '/';
  }
  return absoluteAppPath(rel);
}

/** @param {object} user @param {string} navId */
function userCanAccessNav(user, navId) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const isManager = user.roles.includes('ROLE_MANAGER');
  const orgCount = user.organizations?.length ?? 0;
  const onboardingRequired = user.onboarding?.required;
  if (onboardingRequired) return false;
  if (navId === 'accountProfile' || navId === 'changePassword') {
    return true;
  }
  const needsOrg = !isAdmin && orgCount === 0;
  if (needsOrg) return navId === 'setupOrganization';
  if (navId === 'setupOrganization') return false;
  if (navId === 'organizations' && !isAdmin) return false;
  if ((navId === 'users' || navId === 'usersJournal') && !isAdmin && !isManager) return false;
  if (navId === 'organizationBilling') {
    if (!user.organization) return false;
    return isAdmin || isManager;
  }
  return true;
}

function authScreenFromPath() {
  const path = normalizePath(window.location.pathname);
  if (path === '/inscription') return 'register';
  if (path === '/mot-de-passe-oublie') return 'forgot';
  if (path === '/reinitialisation-mot-de-passe') return 'reset';
  if (path === '/invitation') return 'invitation';
  return 'login';
}

/** Écran obligatoire après connexion si plusieurs organisations. */
function OrganizationContextPicker({ user, onComplete }) {
  const orgs = user.organizations ?? [];
  const [selectedId, setSelectedId] = useState(() => user.organization?.id ?? orgs[0]?.id ?? null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!selectedId) return;
    setBusy(true);
    setError('');
    try {
      const res = await fetch('/api/me/active-organization', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ organizationId: selectedId }),
      });
      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }
      if (!res.ok) {
        setError((data && data.error) || 'Échec de la sélection');
        setBusy(false);
        return;
      }
      sessionStorage.setItem(ORG_SESSION_KEY, '1');
      onComplete();
    } catch {
      setError('Erreur réseau');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="admin-org-context-overlay" role="dialog" aria-modal="true" aria-labelledby="org-context-title">
      <div className="admin-org-context-card">
        <h2 id="org-context-title">Organisation de travail</h2>
        <p>
          Votre compte est rattaché à plusieurs organisations. Choisissez celle dans laquelle vous travaillez pour cette
          session. Vous pourrez la modifier à tout moment depuis le menu latéral.
        </p>
        {error ? (
          <p className="admin-org-context-error" role="alert">
            {error}
          </p>
        ) : null}
        <div className="admin-org-context-list" role="radiogroup" aria-label="Organisations">
          {orgs.map((o) => (
            <label key={o.id} className="admin-org-context-option">
              <input
                type="radio"
                name="org-context"
                checked={selectedId === o.id}
                onChange={() => setSelectedId(o.id)}
              />
              <span>
                <strong>{o.name}</strong>
              </span>
            </label>
          ))}
        </div>
        <button type="button" className="btn" onClick={() => void submit()} disabled={busy || !selectedId}>
          {busy ? 'Validation…' : 'Continuer'}
        </button>
      </div>
    </div>
  );
}

export default function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeNav, setActiveNav] = useState('dashboard');
  /** Chemins profonds (/workflows/2/edit) — tenu au pas avec l’historique */
  const [pathname, setPathname] = useState(() => replaceLegacyWebhooksUrlIfNeeded());
  const [authScreen, setAuthScreen] = useState(() => authScreenFromPath());
  const [authNotice, setAuthNotice] = useState(null);

  const authTitles = useMemo(
    () => ({
      login: { title: 'Connexion', intro: 'Identifiez-vous pour accéder à l’espace.' },
      register: { title: 'Inscription', intro: 'Créez un compte gestionnaire. Un e-mail de confirmation sera envoyé.' },
      forgot: { title: 'Mot de passe oublié', intro: 'Indiquez votre e-mail pour recevoir un lien sécurisé.' },
      reset: { title: 'Nouveau mot de passe', intro: 'Choisissez un mot de passe pour votre compte.' },
      invitation: {
        title: 'Invitation',
        intro: 'Définissez votre mot de passe pour activer l’accès qui vous a été confié.',
      },
    }),
    [],
  );

  const navigateDashboard = useCallback((navId) => {
    setActiveNav(navId);
    const next = navIdToPath(navId);
    if (normalizePath(window.location.pathname) !== next) {
      window.history.pushState({}, '', next);
    }
    setPathname(window.location.pathname);
  }, []);

  const navigateWebhooks = useCallback((route) => {
    const next = pathForWebhooksRoute(route);
    window.history.pushState({}, '', next);
    setPathname(next);
  }, []);

  /** Ouvre un workflow (détail / journaux / édition) depuis le tableau de bord. */
  const openWorkflowFromDashboard = useCallback(
    (route) => {
      setActiveNav('formWebhooks');
      navigateWebhooks(route);
    },
    [navigateWebhooks],
  );

  const webhooksRoute = useMemo(() => {
    if (activeNav !== 'formWebhooks') return { kind: 'list' };
    return parseWebhooksRoute(pathname) ?? { kind: 'list' };
  }, [activeNav, pathname]);

  const refreshSession = useCallback(async (opts = {}) => {
    const quiet = opts.quiet === true;
    if (!quiet) setLoading(true);
    try {
      const res = await fetch('/api/me', { credentials: 'include', headers: { Accept: 'application/json' } });
      if (res.ok) {
        let data = await res.json();
        const isAdm = data.roles?.includes('ROLE_ADMIN');
        const orgs = data.organizations ?? [];
        if (!isAdm && orgs.length === 1 && !data.organization) {
          const r2 = await fetch('/api/me/active-organization', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ organizationId: orgs[0].id }),
          });
          if (r2.ok) {
            data = await r2.json();
          }
        }
        setUser(data);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refreshSession();
  }, [refreshSession]);

  useEffect(() => {
    setAppSessionKnownLoggedIn(!!user);
  }, [user]);

  useEffect(() => {
    const onSessionExpired = () => {
      setAppSessionKnownLoggedIn(false);
      try {
        sessionStorage.removeItem(ORG_SESSION_KEY);
      } catch {
        /* ignore */
      }
      setUser(null);
      setActiveNav('dashboard');
      window.history.replaceState({}, '', '/');
      setPathname('/');
      setAuthScreen('login');
      setAuthNotice({
        type: 'err',
        text: 'Votre session a expiré. Veuillez vous reconnecter.',
      });
      void fetch('/api/logout', { credentials: 'include', method: 'GET' });
    };
    window.addEventListener('webhooky:session-expired', onSessionExpired);
    return () => window.removeEventListener('webhooky:session-expired', onSessionExpired);
  }, []);

  useEffect(() => {
    const onPop = () => {
      let popPath = replaceLegacyWebhooksUrlIfNeeded();
      setPathname(popPath);
      if (!user) {
        setAuthScreen(authScreenFromPath());
        return;
      }
      if (user.onboarding?.required) {
        return;
      }
      const needsOrgSetupOnly = !user.roles.includes('ROLE_ADMIN') && (user.organizations ?? []).length === 0;
      if (needsOrgSetupOnly) {
        const navSetup = pathToNavId(popPath);
        if (navSetup === 'accountProfile' || navSetup === 'changePassword') {
          setActiveNav(navSetup);
          return;
        }
        setActiveNav('setupOrganization');
        return;
      }
      if (popPath === '/mailjet' || popPath === '/mailjets') {
        window.history.replaceState({}, '', '/integrations');
        popPath = '/integrations';
        setPathname('/integrations');
      }
      const nav = pathToNavId(popPath);
      if (nav && userCanAccessNav(user, nav)) {
        setActiveNav(nav);
        return;
      }
      if (nav === null && !AUTH_PATHS.includes(popPath)) {
        setActiveNav('dashboard');
        if (popPath !== '/') {
          window.history.replaceState({}, '', '/');
          setPathname('/');
        }
      }
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [user]);

  useEffect(() => {
    if (!user || loading) return;

    if (user.onboarding?.required) {
      return;
    }

    const needsOrgSetupOnly = !user.roles.includes('ROLE_ADMIN') && (user.organizations ?? []).length === 0;
    if (needsOrgSetupOnly) {
      const navSetup = pathToNavId(window.location.pathname);
      if (navSetup === 'accountProfile' || navSetup === 'changePassword') {
        setActiveNav(navSetup);
        setPathname(window.location.pathname);
        return;
      }
      setActiveNav('setupOrganization');
      if (normalizePath(window.location.pathname) !== '/mon-organisation') {
        window.history.replaceState({}, '', '/mon-organisation');
        setPathname('/mon-organisation');
      }
      return;
    }

    let syncPath = replaceLegacyWebhooksUrlIfNeeded();
    if (syncPath === '/mailjet' || syncPath === '/mailjets') {
      window.history.replaceState({}, '', '/integrations');
      syncPath = '/integrations';
      setPathname('/integrations');
    }

    const nav = pathToNavId(syncPath);
    if (nav && userCanAccessNav(user, nav)) {
      setActiveNav(nav);
      setPathname(syncPath);
      return;
    }

    if (syncPath !== '/') {
      window.history.replaceState({}, '', '/');
      setPathname('/');
    }
    setActiveNav('dashboard');
  }, [user, loading]);

  useEffect(() => {
    if (user) return;
    const q = new URLSearchParams(window.location.search);
    if (q.get('verified') === '1') {
      setAuthNotice({ type: 'ok', text: 'Votre adresse e-mail est confirmée. Vous pouvez vous connecter.' });
      window.history.replaceState({}, '', '/');
      setPathname('/');
      setAuthScreen('login');
    }
    const verr = q.get('verify_error');
    if (verr) {
      const map = {
        missing_token: 'Lien de confirmation incomplet.',
        invalid_token: 'Ce lien de confirmation n’est plus valide.',
        expired: 'Ce lien a expiré. Inscrivez-vous à nouveau ou contactez un administrateur.',
      };
      setAuthNotice({ type: 'err', text: map[verr] ?? 'La confirmation a échoué.' });
      window.history.replaceState({}, '', '/');
      setPathname('/');
      setAuthScreen('login');
    }
  }, [user]);

  const handleLogout = async () => {
    setAppSessionKnownLoggedIn(false);
    await fetch('/api/logout', { credentials: 'include', method: 'GET' });
    try {
      sessionStorage.removeItem(ORG_SESSION_KEY);
    } catch {
      /* ignore */
    }
    setUser(null);
    setActiveNav('dashboard');
    const p = normalizePath(window.location.pathname);
    if (!AUTH_PATHS.includes(p)) {
      window.history.replaceState({}, '', '/');
      setPathname('/');
    } else {
      setPathname(window.location.pathname);
    }
    setAuthScreen(authScreenFromPath());
  };

  useEffect(() => {
    if (user && !user.roles.includes('ROLE_ADMIN') && activeNav === 'organizations') {
      setActiveNav('dashboard');
      window.history.replaceState({}, '', '/');
      setPathname('/');
    }
  }, [user, activeNav]);

  useEffect(() => {
    if (!user) return;
    const isAdmin = user.roles.includes('ROLE_ADMIN');
    const isManager = user.roles.includes('ROLE_MANAGER');
    if (!isAdmin && !isManager && (activeNav === 'users' || activeNav === 'usersJournal')) {
      setActiveNav('dashboard');
      window.history.replaceState({}, '', '/');
      setPathname('/');
    }
  }, [user, activeNav]);

  useEffect(() => {
    if (!user) return;
    const isAdmin = user.roles.includes('ROLE_ADMIN');
    const isManager = user.roles.includes('ROLE_MANAGER');
    if ((!isAdmin && !isManager) || !user.organization) {
      if (activeNav === 'organizationBilling') {
        setActiveNav('dashboard');
        window.history.replaceState({}, '', '/');
        setPathname('/');
      }
    }
  }, [user, activeNav]);

  const switchOrganization = useCallback(
    async (organizationId) => {
      const res = await fetch('/api/me/active-organization', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ organizationId }),
      });
      if (res.ok) {
        try {
          sessionStorage.setItem(ORG_SESSION_KEY, '1');
        } catch {
          /* ignore */
        }
        await refreshSession();
      }
    },
    [refreshSession],
  );

  if (loading) {
    return (
      <div className="admin-loading">
        <div className="admin-loading-inner">
          <div className="admin-spinner" aria-hidden />
          <p>Chargement…</p>
        </div>
      </div>
    );
  }

  if (user) {
    const isAdmin = user.roles.includes('ROLE_ADMIN');
    const orgs = user.organizations ?? [];
    const onboardingRequired = user.onboarding?.required;
    const needsOrgSetup = !isAdmin && !onboardingRequired && orgs.length === 0;
    const showOrgPicker =
      !isAdmin && !onboardingRequired && orgs.length > 1 && !sessionStorage.getItem(ORG_SESSION_KEY);

    if (onboardingRequired) {
      return <OnboardingWizard user={user} onRefresh={() => refreshSession({ quiet: true })} />;
    }

    if (showOrgPicker) {
      return <OrganizationContextPicker user={user} onComplete={() => void refreshSession()} />;
    }

    return (
      <DashboardLayout
        user={user}
        activeNav={activeNav}
        onNavigate={navigateDashboard}
        onLogout={handleLogout}
        onOrganizationSwitch={orgs.length > 1 ? switchOrganization : undefined}
      >
        {needsOrgSetup && activeNav !== 'accountProfile' && activeNav !== 'changePassword' ? (
          <div className="content-card">
            <SetupOrganization onSuccess={refreshSession} onNavigate={navigateDashboard} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'dashboard' ? (
          <DashboardHome
            user={user}
            onNavigate={navigateDashboard}
            onSessionRefresh={refreshSession}
            onOpenWorkflow={openWorkflowFromDashboard}
          />
        ) : null}
        {!needsOrgSetup && activeNav === 'organizations' && user.roles.includes('ROLE_ADMIN') ? (
          <div className="content-card">
            <Organizations user={user} onOrganizationChanged={refreshSession} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'integrations' ? (
          <div className="content-card content-card--integrations-wide">
            <Integrations user={user} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'formWebhooks' ? (
          <div className="content-card">
            <FormWebhooks
              user={user}
              route={webhooksRoute}
              onWebhooksNavigate={navigateWebhooks}
              onAppNavigate={navigateDashboard}
            />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'webhookProjects' ? (
          <WebhookProjects user={user} onNavigate={navigateDashboard} />
        ) : null}
        {!needsOrgSetup &&
        activeNav === 'users' &&
        (user.roles.includes('ROLE_ADMIN') || user.roles.includes('ROLE_MANAGER')) ? (
          <Users user={user} />
        ) : null}
        {!needsOrgSetup &&
        activeNav === 'usersJournal' &&
        (user.roles.includes('ROLE_ADMIN') || user.roles.includes('ROLE_MANAGER')) ? (
          <UsersJournal user={user} />
        ) : null}
        {!needsOrgSetup &&
        activeNav === 'organizationBilling' &&
        user.organization &&
        (user.roles.includes('ROLE_ADMIN') || user.roles.includes('ROLE_MANAGER')) ? (
          <div className="content-card">
            <OrganizationBilling user={user} onSessionRefresh={refreshSession} />
          </div>
        ) : null}
        {activeNav === 'accountProfile' ? (
          <div className="content-card">
            <AccountProfile user={user} onSessionRefresh={refreshSession} />
          </div>
        ) : null}
        {activeNav === 'changePassword' ? (
          <div className="content-card">
            <AccountChangePassword />
          </div>
        ) : null}
      </DashboardLayout>
    );
  }

  const { title: authTitle, intro: authIntro } = authTitles[authScreen] ?? authTitles.login;

  return (
    <div className="login-split">
      <div className="login-split-brand">
        <div className="login-brand-inner">
          <span className="login-brand-badge" />
          <h1 className="login-brand-title">Webhooky Builders</h1>
          <p className="login-brand-tagline">
            Automatisez vos webhooks et vos envois (Mailjet) — tableau de bord pour vos organisations, sur webhooky.builders.
          </p>
          <ul className="login-brand-list">
            <li>Sessions sécurisées</li>
            <li>Clés API Mailjet par organisation</li>
            <li>Interface type console admin</li>
          </ul>
        </div>
      </div>
      <div className="login-split-form">
        <div className="login-form-shell">
          <h2 className="login-form-title">{authTitle}</h2>
          <p className="login-form-intro">{authIntro}</p>
          {authNotice ? (
            <div className={`auth-notice ${authNotice.type === 'ok' ? 'ok' : 'err'}`} role="status">
              {authNotice.text}
            </div>
          ) : null}
          {authScreen === 'login' ? <LoginForm onSuccess={refreshSession} /> : null}
          {authScreen === 'register' ? <RegisterForm /> : null}
          {authScreen === 'forgot' ? <ForgotPasswordForm /> : null}
          {authScreen === 'reset' ? <ResetPasswordForm /> : null}
          {authScreen === 'invitation' ? <InvitationForm /> : null}
          <p className="login-vitrine-foot">
            <a
              href="https://webhooky.fr"
              className="login-inline-link"
              target="_blank"
              rel="noopener noreferrer"
            >
              Site vitrine — webhooky.fr
            </a>
          </p>
        </div>
      </div>
    </div>
  );
}
