import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { userNeedsOrganizationSetup } from '../app/routing.js';

const TOPBAR_SECTION_LABELS = {
  accountProfile: 'Mon profil',
  changePassword: 'Changer mon mot de passe',
  usersJournal: 'Journal des actions',
  adminSupervision: 'Supervision',
  adminOptions: 'Options plateforme',
};

const NAV_SETUP_ONLY = [{ id: 'setupOrganization', label: 'Mon organisation', icon: MdiDomain }];

const NAV_DASHBOARD = { id: 'dashboard', label: 'Tableau de bord', icon: MdiViewDashboard };

const NAV_ORGANIZATIONS = { id: 'organizations', label: 'Organisations', icon: MdiDomain };

const NAV_ADMIN_SUPERVISION = { id: 'adminSupervision', label: 'Supervision', icon: MdiShieldAlert };

const NAV_ADMIN_OPTIONS = { id: 'adminOptions', label: 'Options', icon: MdiTune };

const NAV_USERS = { id: 'users', label: 'Utilisateurs', icon: MdiPeople };

const NAV_ORG_BILLING = { id: 'organizationBilling', label: 'Organisation & facturation', icon: MdiInvoice };

/** Bloc « gestion des workflows » : ordre imposé — projets, connecteurs, écran workflows. */
const NAV_WEBHOOK_STACK = [
  { id: 'webhookProjects', label: 'Projets', icon: MdiFolder },
  { id: 'integrations', label: 'Intégrations', icon: MdiIntegrationHub },
  { id: 'formWebhooks', label: 'Workflows', icon: MdiWebhook },
];

const SECTION_ADMIN = 'Administration';
const SECTION_WEBHOOK = 'Workflows';

/**
 * @returns {{ sectionLabel: string | null; items: typeof NAV_WEBHOOK_STACK }[]}
 */
function navSectionsForUser(user) {
  const roles = Array.isArray(user.roles) ? user.roles : [];
  const isAdmin = roles.includes('ROLE_ADMIN');
  const isManager = roles.includes('ROLE_MANAGER');
  const orgCount = user.organizations?.length ?? 0;
  if (userNeedsOrganizationSetup(user)) {
    return [{ sectionLabel: null, items: NAV_SETUP_ONLY }];
  }

  const adminItems = [NAV_DASHBOARD];
  if (isAdmin) {
    adminItems.push(NAV_ORGANIZATIONS);
    adminItems.push(NAV_ADMIN_SUPERVISION);
    adminItems.push(NAV_ADMIN_OPTIONS);
  }
  const canManageTeam = isAdmin || isManager;
  if (canManageTeam && user.organization) {
    adminItems.push(NAV_ORG_BILLING);
  }
  if (canManageTeam) {
    adminItems.push(NAV_USERS);
  }

  return [
    { sectionLabel: SECTION_ADMIN, items: adminItems },
    { sectionLabel: SECTION_WEBHOOK, items: [...NAV_WEBHOOK_STACK] },
  ];
}

/** Liste plate (fil d’Ariane, recherche d’intitulé actif). */
function flatNavItemsFromSections(sections) {
  return sections.flatMap((s) => s.items);
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

function MdiShieldAlert() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16 2-4.18L14 17h-4zm2-9.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.71z"
      />
    </svg>
  );
}

function MdiTune() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm12 4v-2H11v2h8zm0-4v-2h-4v2h4z"
      />
    </svg>
  );
}

function MdiIntegrationHub() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"
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

function MdiFolder() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"
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

function MdiInvoice() {
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden>
      <path
        fill="currentColor"
        d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-2-4H8v-2h8v2zm0-4H8v-2h8v2z"
      />
    </svg>
  );
}

function IconChevronDown({ open }) {
  return (
    <svg
      className="admin-user-chevron"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      aria-hidden
      style={{ transform: open ? 'rotate(180deg)' : undefined, transition: 'transform 0.15s ease' }}
    >
      <path fill="currentColor" d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z" />
    </svg>
  );
}

/**
 * @typedef {{ label: string; current?: boolean; onActivate?: () => void }} BreadcrumbItem
 */

export default function DashboardLayout({
  user,
  activeNav,
  onNavigate,
  onLogout,
  onOrganizationSwitch,
  /** @type {BreadcrumbItem[] | null | undefined} */
  breadcrumb,
  children,
}) {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const userMenuRef = useRef(null);

  const roles = Array.isArray(user.roles) ? user.roles : [];
  const accountLabel =
    [user.displayName, user.email].find((x) => typeof x === 'string' && x.trim() !== '')?.trim() ?? '?';

  const isAdmin = roles.includes('ROLE_ADMIN');
  const navSections = useMemo(() => navSectionsForUser(user), [user]);
  const flatNavItems = useMemo(() => flatNavItemsFromSections(navSections), [navSections]);
  const multiOrg = !isAdmin && (user.organizations?.length ?? 0) > 1;

  const sidebarNavId = activeNav === 'usersJournal' ? 'users' : activeNav;
  const topBarSectionLabel =
    TOPBAR_SECTION_LABELS[activeNav] ??
    flatNavItems.find((n) => n.id === sidebarNavId)?.label ??
    '—';

  useEffect(() => {
    if (!userMenuOpen) return;
    const onDoc = (e) => {
      if (userMenuRef.current && !userMenuRef.current.contains(e.target)) {
        setUserMenuOpen(false);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setUserMenuOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [userMenuOpen]);

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
            <strong>Webhooky Builders</strong>
            <small>webhooky.builders</small>
          </div>
        </div>

        <nav className="admin-nav" aria-label="Navigation principale">
          {navSections.map((section, si) => (
            <div key={section.sectionLabel ?? `nav-section-${si}`} className="admin-nav-section">
              {section.sectionLabel ? <p className="admin-nav-section-title">{section.sectionLabel}</p> : null}
              {section.items.map((item) => {
                const active = sidebarNavId === item.id;
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
            </div>
          ))}
        </nav>

        <div className="admin-sidebar-footer">
          <p className="admin-sidebar-footnote">
            {multiOrg && typeof onOrganizationSwitch === 'function' ? (
              <label className="admin-org-switch">
                <span className="admin-org-label">Organisation active</span>
                <select
                  className="admin-org-select"
                  value={user.organization?.id ?? ''}
                  onChange={(e) => {
                    const id = Number(e.target.value);
                    if (id) void onOrganizationSwitch(id);
                  }}
                  aria-label="Changer d’organisation"
                >
                  {(user.organizations ?? []).map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </select>
              </label>
            ) : user.organization ? (
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

          <nav className="admin-breadcrumb" aria-label="Fil d’Ariane">
            {breadcrumb && breadcrumb.length > 0 ? (
              breadcrumb.map((item, i) => (
                <Fragment key={`${item.label}-${i}`}>
                  {i > 0 ? <span className="admin-breadcrumb-sep">/</span> : null}
                  {item.current ? (
                    <span className="admin-breadcrumb-current">{item.label}</span>
                  ) : (
                    <button
                      type="button"
                      className="admin-breadcrumb-link"
                      onClick={() => item.onActivate?.()}
                    >
                      {item.label}
                    </button>
                  )}
                </Fragment>
              ))
            ) : (
              <>
                <span className="admin-breadcrumb-muted">Espace connecté</span>
                <span className="admin-breadcrumb-sep">/</span>
                <span className="admin-breadcrumb-current">{topBarSectionLabel}</span>
              </>
            )}
          </nav>

          <div className="admin-topbar-right">
            <div className="admin-user-menu" ref={userMenuRef}>
              <button
                type="button"
                className="admin-user-menu-trigger"
                aria-expanded={userMenuOpen}
                aria-haspopup="menu"
                aria-label="Menu compte et déconnexion"
                onClick={() => setUserMenuOpen((o) => !o)}
              >
                <span className="admin-user-avatar" aria-hidden>
                  {accountLabel.slice(0, 1).toUpperCase()}
                </span>
                <div className="admin-user-meta">
                  <span className="admin-user-email">{accountLabel}</span>
                  <span className="admin-user-role">
                    {isAdmin
                      ? 'Administrateur'
                      : roles.includes('ROLE_MANAGER')
                        ? 'Gestionnaire'
                        : 'Utilisateur'}
                  </span>
                </div>
                <IconChevronDown open={userMenuOpen} />
              </button>
              {userMenuOpen ? (
                <div className="admin-user-menu-dropdown" role="menu" aria-label="Compte">
                  <button
                    type="button"
                    className="admin-user-menu-item"
                    role="menuitem"
                    onClick={() => {
                      setUserMenuOpen(false);
                      onNavigate('accountProfile');
                    }}
                  >
                    Mon profil
                  </button>
                  <button
                    type="button"
                    className="admin-user-menu-item"
                    role="menuitem"
                    onClick={() => {
                      setUserMenuOpen(false);
                      onNavigate('changePassword');
                    }}
                  >
                    Changer mon mot de passe
                  </button>
                  <button
                    type="button"
                    className="admin-user-menu-item admin-user-menu-item--danger"
                    role="menuitem"
                    onClick={() => {
                      setUserMenuOpen(false);
                      void onLogout();
                    }}
                  >
                    Déconnexion
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        </header>

        <main className="admin-main">{children}</main>
      </div>
    </div>
  );
}
