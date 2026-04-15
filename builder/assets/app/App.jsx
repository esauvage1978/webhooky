import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import AccountChangePassword from '../account/AccountChangePassword.jsx';
import AccountProfile from '../account/AccountProfile.jsx';
import RouteFallback from '../components/feedback/RouteFallback.jsx';
import ForgotPasswordForm from '../auth/ForgotPasswordForm.jsx';
import InvitationForm from '../auth/InvitationForm.jsx';
import LoginForm from '../auth/LoginForm.jsx';
import RegisterForm from '../auth/RegisterForm.jsx';
import ResetPasswordForm from '../auth/ResetPasswordForm.jsx';
import DashboardHome from '../dashboard/DashboardHome.jsx';
import Integrations from '../integrations/Integrations.jsx';
import DashboardLayout from '../layout/DashboardLayout.jsx';
import { apiJsonInit, parseJson } from '../lib/http.js';
import { setAppSessionKnownLoggedIn } from '../lib/sessionFetch.js';
import OnboardingWizard from '../organization/OnboardingWizard.jsx';
import SetupOrganization from '../organization/SetupOrganization.jsx';
import Users from '../users/Users.jsx';
import UsersJournal from '../users/UsersJournal.jsx';

const AdminSupervision = lazy(() => import('../admin/AdminSupervision.jsx'));
const AdminOptions = lazy(() => import('../admin/AdminOptions.jsx'));
const FormWebhooks = lazy(() => import('../workflows/FormWebhooks.jsx'));
const OrganizationBilling = lazy(() => import('../organization/OrganizationBilling.jsx'));
const Organizations = lazy(() => import('../organization/Organizations.jsx'));
const WebhookProjects = lazy(() => import('../workflows/WebhookProjects.jsx'));
const SeoInsights = lazy(() => import('../seo/SeoInsights.jsx'));
import OrganizationContextPicker from './OrganizationContextPicker.jsx';
import {
  AUTH_PATHS,
  ORG_SESSION_KEY,
  authScreenFromPath,
  navIdToPath,
  normalizePath,
  parseWebhooksRoute,
  pathForWebhooksRoute,
  pathToNavId,
  replaceLegacyWebhooksUrlIfNeeded,
  userCanAccessNav,
  userNeedsOrganizationSetup,
} from './routing.js';

/**
 * Rôles Symfony / JSON : tableau attendu ; tolère chaîne unique ou objet indexé (sinon l’UI croit ROLE_* absent).
 */
function normalizeRoles(raw) {
  if (Array.isArray(raw)) {
    return raw.filter((r) => typeof r === 'string' && r.trim() !== '');
  }
  if (typeof raw === 'string' && raw.trim() !== '') {
    return [raw.trim()];
  }
  if (raw != null && typeof raw === 'object') {
    return Object.values(raw).filter((r) => typeof r === 'string' && r.trim() !== '');
  }
  return [];
}

/** Extrait l’e-mail quel que soit le nom de clé (proxies, réponses enveloppées, variantes). */
function pickEmailFromMePayload(obj) {
  if (!obj || typeof obj !== 'object') return '';
  const keys = ['email', 'Email', 'userIdentifier', 'username', 'user_identifier', 'mail'];
  for (const k of keys) {
    const v = obj[k];
    if (typeof v === 'string' && v.trim() !== '') return v.trim();
  }
  return '';
}

/** Préfère le premier tableau `organizations` non vide (réponses enveloppées type { data: { … } }). */
function pickOrganizationsFromPayload(data, nestedUser, wrapped) {
  const objs = [data, nestedUser, wrapped].filter((o) => o != null && typeof o === 'object');
  for (const o of objs) {
    if (Array.isArray(o.organizations) && o.organizations.length > 0) return o.organizations;
  }
  for (const o of objs) {
    if (Array.isArray(o.organizations)) return o.organizations;
  }
  return [];
}

function pickOrganizationRef(data, nestedUser, wrapped) {
  const objs = [data, nestedUser, wrapped].filter((o) => o != null && typeof o === 'object');
  for (const o of objs) {
    const r = o.organization;
    if (r != null && typeof r === 'object' && r.id != null) return r;
  }
  return null;
}

/** Garantit champs attendus par l’UI (évite page blanche si l’API omet des clés ou renvoie null). */
function normalizeMePayload(data) {
  if (!data || typeof data !== 'object') return data;
  const nestedUser = data.user != null && typeof data.user === 'object' ? data.user : null;
  const wrapped =
    data.data != null && typeof data.data === 'object' && !Array.isArray(data.data) ? data.data : null;
  const email =
    pickEmailFromMePayload(data) ||
    pickEmailFromMePayload(nestedUser) ||
    pickEmailFromMePayload(wrapped) ||
    '';
  const dnRaw = data.displayName ?? nestedUser?.displayName ?? wrapped?.displayName;
  const displayName =
    typeof dnRaw === 'string'
      ? dnRaw.trim()
      : dnRaw != null && String(dnRaw).trim() !== ''
        ? String(dnRaw).trim()
        : '';
  const rolesRaw = data.roles ?? nestedUser?.roles ?? wrapped?.roles;
  let organizations = pickOrganizationsFromPayload(data, nestedUser, wrapped);
  const orgRef = pickOrganizationRef(data, nestedUser, wrapped);
  const organization = orgRef;
  if (organizations.length === 0 && orgRef != null && orgRef.id != null) {
    organizations = [{ id: orgRef.id, name: typeof orgRef.name === 'string' ? orgRef.name : '' }];
  }
  const onboarding = data.onboarding ?? nestedUser?.onboarding ?? wrapped?.onboarding;
  const subscription = data.subscription ?? nestedUser?.subscription ?? wrapped?.subscription;
  return {
    ...data,
    email,
    displayName: displayName || email || 'Compte',
    roles: normalizeRoles(rolesRaw),
    organization,
    organizations,
    ...(onboarding !== undefined ? { onboarding } : {}),
    ...(subscription !== undefined ? { subscription } : {}),
  };
}

export default function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeNav, setActiveNav] = useState('dashboard');
  /** Chemins profonds (/workflows/2/edit) — tenu au pas avec l’historique */
  const [pathname, setPathname] = useState(() => replaceLegacyWebhooksUrlIfNeeded());
  const [authScreen, setAuthScreen] = useState(() => authScreenFromPath());
  const [authNotice, setAuthNotice] = useState(null);

  const forceLogoutToLogin = useCallback((notice) => {
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
    if (notice) setAuthNotice(notice);
    void fetch('/api/logout', apiJsonInit({ method: 'GET' }));
  }, []);

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

  /** Fil d’Ariane cliquable (liste / fiche / édition / journaux workflows). */
  const workflowBreadcrumb = useMemo(() => {
    if (activeNav !== 'formWebhooks') return null;
    const r = parseWebhooksRoute(pathname);
    const accueil = { label: 'Accueil', onActivate: () => navigateDashboard('dashboard') };

    if (!r || r.kind === 'list') {
      return [
        accueil,
        { label: 'Workflows', current: true },
      ];
    }

    const id = r.id;
    const workflowsLink = { label: 'Workflows', onActivate: () => navigateDashboard('formWebhooks') };

    if (r.kind === 'detail') {
      return [accueil, workflowsLink, { label: String(id), current: true }];
    }

    if (r.kind === 'edit') {
      return [
        accueil,
        workflowsLink,
        {
          label: String(id),
          onActivate: () => {
            setActiveNav('formWebhooks');
            navigateWebhooks({ kind: 'detail', id });
          },
        },
        { label: 'Édition', current: true },
      ];
    }

    if (r.kind === 'logs') {
      return [
        accueil,
        workflowsLink,
        {
          label: String(id),
          onActivate: () => {
            setActiveNav('formWebhooks');
            navigateWebhooks({ kind: 'detail', id });
          },
        },
        { label: 'Journaux', current: true },
      ];
    }

    return null;
  }, [activeNav, pathname, navigateDashboard, navigateWebhooks]);

  const refreshSession = useCallback(async (opts = {}) => {
    const quiet = opts.quiet === true;
    if (!quiet) setLoading(true);
    try {
      const res = await fetch('/api/me', apiJsonInit());
      if (res.ok) {
        let data = await parseJson(res);
        if (!data || typeof data !== 'object') {
          forceLogoutToLogin(null);
          return;
        }
        data = normalizeMePayload(data);
        if (!data.email || !Array.isArray(data.roles) || data.roles.length === 0) {
          forceLogoutToLogin(null);
          return;
        }
        const isAdm = data.roles.includes('ROLE_ADMIN');
        const orgs = data.organizations;
        if (!isAdm && orgs.length === 1 && !data.organization) {
          const r2 = await fetch(
            '/api/me/active-organization',
            apiJsonInit({
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ organizationId: orgs[0].id }),
            }),
          );
          if (r2.ok) {
            const again = await parseJson(r2);
            if (again && typeof again === 'object') {
              data = normalizeMePayload(again);
            }
          }
        }
        setUser(normalizeMePayload(data));
      } else {
        forceLogoutToLogin(null);
      }
    } catch {
      forceLogoutToLogin(null);
    } finally {
      if (!quiet) setLoading(false);
    }
  }, [forceLogoutToLogin]);

  useEffect(() => {
    void refreshSession();
  }, [refreshSession]);

  useEffect(() => {
    setAppSessionKnownLoggedIn(!!user);
  }, [user]);

  useEffect(() => {
    const onSessionExpired = () => {
      forceLogoutToLogin({
        type: 'err',
        text: 'Votre session a expiré. Veuillez vous reconnecter.',
      });
    };
    window.addEventListener('webhooky:session-expired', onSessionExpired);
    return () => window.removeEventListener('webhooky:session-expired', onSessionExpired);
  }, [forceLogoutToLogin]);

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
      const needsOrgSetupOnly = userNeedsOrganizationSetup(user);
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

    const needsOrgSetupOnly = userNeedsOrganizationSetup(user);
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
    await fetch('/api/logout', apiJsonInit({ method: 'GET' }));
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
    if (user && !user.roles.includes('ROLE_ADMIN') && activeNav === 'adminSupervision') {
      setActiveNav('dashboard');
      window.history.replaceState({}, '', '/');
      setPathname('/');
    }
  }, [user, activeNav]);

  useEffect(() => {
    if (user && !user.roles.includes('ROLE_ADMIN') && activeNav === 'adminOptions') {
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
      const res = await fetch(
        '/api/me/active-organization',
        apiJsonInit({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ organizationId }),
        }),
      );
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
    const needsOrgSetup = !onboardingRequired && userNeedsOrganizationSetup(user);
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
        breadcrumb={workflowBreadcrumb}
      >
        {needsOrgSetup && activeNav !== 'accountProfile' && activeNav !== 'changePassword' ? (
          <SetupOrganization onSuccess={refreshSession} onNavigate={navigateDashboard} />
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
          <Suspense fallback={<RouteFallback />}>
            <Organizations user={user} onOrganizationChanged={refreshSession} />
          </Suspense>
        ) : null}
        {!needsOrgSetup && activeNav === 'adminSupervision' && user.roles.includes('ROLE_ADMIN') ? (
          <Suspense fallback={<RouteFallback />}>
            <AdminSupervision />
          </Suspense>
        ) : null}
        {!needsOrgSetup && activeNav === 'adminOptions' && user.roles.includes('ROLE_ADMIN') ? (
          <Suspense fallback={<RouteFallback />}>
            <AdminOptions />
          </Suspense>
        ) : null}
        {!needsOrgSetup && activeNav === 'integrations' ? (
          <Integrations user={user} />
        ) : null}
        {!needsOrgSetup && activeNav === 'seoInsights' ? (
          <Suspense fallback={<RouteFallback />}>
            <SeoInsights user={user} />
          </Suspense>
        ) : null}
        {!needsOrgSetup && activeNav === 'formWebhooks' ? (
          <Suspense fallback={<RouteFallback />}>
            <FormWebhooks
              user={user}
              route={webhooksRoute}
              onWebhooksNavigate={navigateWebhooks}
              onAppNavigate={navigateDashboard}
            />
          </Suspense>
        ) : null}
        {!needsOrgSetup && activeNav === 'webhookProjects' ? (
          <Suspense fallback={<RouteFallback />}>
            <WebhookProjects user={user} onNavigate={navigateDashboard} />
          </Suspense>
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
          <Suspense fallback={<RouteFallback />}>
            <OrganizationBilling user={user} onSessionRefresh={refreshSession} />
          </Suspense>
        ) : null}
        {activeNav === 'accountProfile' ? (
          <AccountProfile user={user} onSessionRefresh={refreshSession} />
        ) : null}
        {activeNav === 'changePassword' ? (
          <AccountChangePassword />
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
