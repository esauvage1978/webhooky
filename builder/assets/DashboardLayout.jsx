import { useMemo, useState } from 'react';

const NAV_SETUP_ONLY = [{ id: 'setupOrganization', label: 'Mon organisation', icon: MdiDomain }];

const NAV_FULL_BASE = [
  { id: 'dashboard', label: 'Tableau de bord', icon: MdiViewDashboard },
  { id: 'organizations', label: 'Organisations', icon: MdiDomain },
  { id: 'mailjets', label: 'Mailjet', icon: MdiEmailLock },
  { id: 'formWebhooks', label: 'Webhooks', icon: MdiWebhook },
];

const NAV_USERS = { id: 'users', label: 'Utilisateurs', icon: MdiPeople };

function navForUser(user) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const isManager = user.roles.includes('ROLE_MANAGER');
  if (!isAdmin && !user.organization) {
    return NAV_SETUP_ONLY;
  }
  let items = isAdmin ? [...NAV_FULL_BASE] : NAV_FULL_BASE.filter((item) => item.id !== 'organizations');
  if (isAdmin || isManager) {
    const dashIdx = items.findIndex((i) => i.id === 'dashboard');
    if (dashIdx >= 0) {
      items = [...items.slice(0, dashIdx + 1), NAV_USERS, ...items.slice(dashIdx + 1)];
    } else {
      items = [NAV_USERS, ...items];
    }
  }
  return items;
}

function MdiViewDashboard() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path fill="currentColor" d="M3 13v8h8v-8H3zm10 0v8h8v-8h-8zM3 3v8h8V3H3zm10 0v8h8V3h-8z" />
    </svg>
  );
}

function MdiDomain() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M12 7V5c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2h-8zm-2 12H4v-2h6v2zm0-4H4v-2h6v2zm0-4H4V9h6v2zm0-4H4V5h6v2zm8 12h-6v-2h6v2zm0-4h-6v-2h6v2zm0-4h-6V9h6v2zm0-4h-6V5h6v2z"
      />
    </svg>
  );
}

function MdiEmailLock() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M22 17V7l-10 6L2 7v10h16v2H2c-1.1 0-2-.9-2-2V5c0-1.1.9-2 2-2h20c1.1 0 2 .9 2 2v10h-2zm-2 1.5c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5zm1.5-5V9h-9v4h7c.8 0 1.5.7 1.5 1.5H21z"
      />
    </svg>
  );
}

function MdiWebhook() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M6 15.5c.83 0 1.5-.67 1.5-1.5S6.83 12.5 6 12.5 4.5 13.17 4.5 14 5.17 15.5 6 15.5m5.5-3c.83 0 1.5-.67 1.5-1.5S12.33 9.5 11.5 9.5 10 10.17 10 11s.67 1.5 1.5 1.5m4 3c.83 0 1.5-.67 1.5-1.5S16.33 12.5 15.5 12.5 14 13.17 14 14s.67 1.5 1.5 1.5M12 1C5.93 1 1 5.93 1 12s4.93 11 11 11 11-4.93 11-11S18.07 1 12 1m0 20c-4.96 0-9-4.04-9-9 0-2.11.74-4.06 1.97-5.6L18.6 18.03C17.06 19.26 15.11 20 12 20m7.03-5.4L5.4 5.97C6.94 4.74 8.89 4 12 4c4.96 0 9 4.04 9 9 0 2.11-.74 4.06-1.97 5.6z"
      />
    </svg>
  );
}

function MdiPeople() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M16.5 13c-1.2 0-3.07.34-4.5 1-1.43-.67-3.3-1-4.5-1C5.33 13 1 14.08 1 16.25V19h22v-2.75c0-2.17-4.33-3.25-6.5-3.25zm-4 4.5h-10v-1.25c0-.54 2.56-1.75 5-1.75s5 1.21 5 1.75V17.5zm9 0h-8v-1.25c0-.46-.2-.86-.52-1.22.88-.28 1.98-.44 2.52-.44 2.44 0 5 1.21 5 1.75V17.5zM12 12c1.93 0 3.5-1.57 3.5-3.5S13.93 5 12 5 8.5 6.57 8.5 8.5 10.07 12 12 12zm0-5c.83 0 1.5.67 1.5 1.5S12.83 10 12 10s-1.5-.67-1.5-1.5S11.17 7 12 7zm5.5 5c1.93 0 3.5-1.57 3.5-3.5S19.43 5 17.5 5c-.51 0-.99.1-1.42.28.52.85.82 1.85.82 2.94 0 1.41-.54 2.7-1.42 3.68.5.06 1.02.1 1.52.1z"
      />
    </svg>
  );
}

export default function DashboardLayout({ user, activeNav, onNavigate, onLogout, children }) {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const navItems = useMemo(() => navForUser(user), [user]);

  return (
    <div className="admin-app">
      <div
        className={`admin-sidebar-backdrop ${mobileMenuOpen ? 'visible' : ''}`}
        onClick={() => setMobileMenuOpen(false)}
        onKeyDown={(e) => e.key === 'Escape' && setMobileMenuOpen(false)}
        role="presentation"
        aria-hidden
      />

      <aside className={`admin-sidebar ${mobileMenuOpen ? 'mobile-open' : ''}`}>
        <div className="admin-brand">
          <span className="admin-brand-mark" aria-hidden />
          <div className="admin-brand-text">
            <strong>Webhooky</strong>
            <small>webhooky.fr</small>
          </div>
        </div>

        <nav className="admin-nav" aria-label="Navigation principale">
          {navItems.map((item) => {
            const active = activeNav === item.id;
            const Icon = item.icon;
            return (
              <button
                key={item.id}
                type="button"
                className={`admin-nav-item ${active ? 'active' : ''}`}
                onClick={() => {
                  onNavigate(item.id);
                  setMobileMenuOpen(false);
                }}
              >
                <Icon />
                <span>{item.label}</span>
              </button>
            );
          })}
        </nav>

        <div className="admin-sidebar-footer">
          <p className="admin-sidebar-footnote">
            {user.organization ? (
              <>
                <span className="admin-org-label">Organisation</span>
                <span className="admin-org-name">{user.organization.name}</span>
              </>
            ) : (
              <span className="admin-org-muted">Aucune organisation</span>
            )}
          </p>
        </div>
      </aside>

      <div className="admin-shell">
        <header className="admin-topbar">
          <button
            type="button"
            className="admin-burger"
            onClick={() => setMobileMenuOpen((o) => !o)}
            aria-expanded={mobileMenuOpen}
            aria-label="Ouvrir le menu"
          >
            <span />
            <span />
            <span />
          </button>

          <div className="admin-breadcrumb">
            <span className="admin-breadcrumb-muted">Espace connecté</span>
            <span className="admin-breadcrumb-sep">/</span>
            <span className="admin-breadcrumb-current">
              {navItems.find((n) => n.id === activeNav)?.label ?? '—'}
            </span>
          </div>

          <div className="admin-topbar-right">
            <div className="admin-user-pill">
              <span className="admin-user-avatar" aria-hidden>
                {user.email.slice(0, 1).toUpperCase()}
              </span>
              <div className="admin-user-meta">
                <span className="admin-user-email">{user.email}</span>
                <span className="admin-user-role">
                  {isAdmin
                    ? 'Administrateur'
                    : user.roles.includes('ROLE_MANAGER')
                      ? 'Gestionnaire'
                      : 'Utilisateur'}
                </span>
              </div>
            </div>
            <button type="button" className="btn btn-logout" onClick={() => void onLogout()}>
              Déconnexion
            </button>
          </div>
        </header>

        <main className="admin-main">{children}</main>
      </div>
    </div>
  );
}
