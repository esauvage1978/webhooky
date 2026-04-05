import { useCallback, useEffect, useMemo, useState } from 'react';
import LoginForm from './LoginForm.jsx';
import ForgotPasswordForm from './ForgotPasswordForm.jsx';
import RegisterForm from './RegisterForm.jsx';
import ResetPasswordForm from './ResetPasswordForm.jsx';
import DashboardLayout from './DashboardLayout.jsx';
import DashboardHome from './DashboardHome.jsx';
import Organizations from './Organizations.jsx';
import Mailjets from './Mailjets.jsx';
import FormWebhooks from './FormWebhooks.jsx';
import SetupOrganization from './SetupOrganization.jsx';
import InvitationForm from './InvitationForm.jsx';
import Users from './Users.jsx';

function authScreenFromPath() {
  const path = window.location.pathname.replace(/\/$/, '') || '/';
  if (path === '/inscription') return 'register';
  if (path === '/mot-de-passe-oublie') return 'forgot';
  if (path === '/reinitialisation-mot-de-passe') return 'reset';
  if (path === '/invitation') return 'invitation';
  return 'login';
}

export default function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeNav, setActiveNav] = useState('dashboard');
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

  const refreshSession = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/me', { credentials: 'include' });
      if (res.ok) {
        const data = await res.json();
        setUser(data);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refreshSession();
  }, [refreshSession]);

  useEffect(() => {
    const onPop = () => setAuthScreen(authScreenFromPath());
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  useEffect(() => {
    if (user) return;
    const q = new URLSearchParams(window.location.search);
    if (q.get('verified') === '1') {
      setAuthNotice({ type: 'ok', text: 'Votre adresse e-mail est confirmée. Vous pouvez vous connecter.' });
      window.history.replaceState({}, '', '/');
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
      setAuthScreen('login');
    }
  }, [user]);

  const handleLogout = async () => {
    await fetch('/api/logout', { credentials: 'include', method: 'GET' });
    setUser(null);
    setActiveNav('dashboard');
  };

  useEffect(() => {
    if (user && !user.roles.includes('ROLE_ADMIN') && activeNav === 'organizations') {
      setActiveNav('dashboard');
    }
  }, [user, activeNav]);

  useEffect(() => {
    if (!user) return;
    const isAdmin = user.roles.includes('ROLE_ADMIN');
    const isManager = user.roles.includes('ROLE_MANAGER');
    if (!isAdmin && !isManager && activeNav === 'users') {
      setActiveNav('dashboard');
    }
  }, [user, activeNav]);

  useEffect(() => {
    if (!user) return;
    if (user.roles.includes('ROLE_ADMIN')) return;
    if (user.organization) return;
    setActiveNav('setupOrganization');
  }, [user]);

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
    const needsOrgSetup = !user.roles.includes('ROLE_ADMIN') && !user.organization;

    return (
      <DashboardLayout
        user={user}
        activeNav={activeNav}
        onNavigate={setActiveNav}
        onLogout={handleLogout}
      >
        {needsOrgSetup ? (
          <div className="content-card">
            <SetupOrganization onSuccess={refreshSession} onNavigate={setActiveNav} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'dashboard' ? (
          <DashboardHome user={user} onNavigate={setActiveNav} onSessionRefresh={refreshSession} />
        ) : null}
        {!needsOrgSetup && activeNav === 'organizations' && user.roles.includes('ROLE_ADMIN') ? (
          <div className="content-card">
            <Organizations user={user} onOrganizationChanged={refreshSession} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'mailjets' ? (
          <div className="content-card">
            <Mailjets user={user} />
          </div>
        ) : null}
        {!needsOrgSetup && activeNav === 'formWebhooks' ? (
          <div className="content-card">
            <FormWebhooks user={user} />
          </div>
        ) : null}
        {!needsOrgSetup &&
        activeNav === 'users' &&
        (user.roles.includes('ROLE_ADMIN') || user.roles.includes('ROLE_MANAGER')) ? (
          <div className="content-card">
            <Users user={user} />
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
          <h1 className="login-brand-title">Webhooky</h1>
          <p className="login-brand-tagline">
            Automatisez vos webhooks et vos envois (Mailjet) — Webhooky, tableau de bord pour vos organisations.
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
        </div>
      </div>
    </div>
  );
}
