import { absoluteAppPath } from '../lib/paths.js';

export const ORG_SESSION_KEY = 'webhookyOrgSessionOk';

export const AUTH_PATHS = [
  '/inscription',
  '/mot-de-passe-oublie',
  '/reinitialisation-mot-de-passe',
  '/invitation',
];

export function normalizePath(pathname) {
  return (pathname || '/').replace(/\/$/, '') || '/';
}

/**
 * Chemins /workflows avec préfixe d’appli (ex. /public/workflows/5/edit).
 * Ne pas ancrer en `^/workflows` : sinon sous-répertoire → null → l’UI traite la route comme « liste » et vide l’éditeur.
 *
 * @returns {{ kind: 'list' } | { kind: 'detail'; id: number } | { kind: 'edit'; id: number } | { kind: 'logs'; id: number } | null}
 */
export function parseWebhooksRoute(pathname) {
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
export function pathForWebhooksRoute(route) {
  const root = absoluteAppPath('/workflows');
  if (!route || route.kind === 'list') return root;
  if (route.kind === 'detail') return `${root}/${route.id}`;
  if (route.kind === 'edit') return `${root}/${route.id}/edit`;
  if (route.kind === 'logs') return `${root}/${route.id}/logs`;
  return root;
}

/** Ancienne URL publique « …/webhooks » → « …/workflows » (avec ou sans préfixe d’appli). */
export function replaceLegacyWebhooksUrlIfNeeded() {
  const p = normalizePath(window.location.pathname);
  if (/(^|\/)webhooks(\/|$)/.test(p)) {
    const next = normalizePath(p.replace(/(^|\/)webhooks(?=\/|$)/g, '$1workflows'));
    window.history.replaceState({}, '', next);
    return next;
  }
  return p;
}

/** @param {string} pathname */
export function pathToNavId(pathname) {
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
  if (/(^|\/)admin\/supervision$/.test(p)) {
    return 'adminSupervision';
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
export function navIdToPath(navId) {
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
    case 'adminSupervision':
      rel = '/admin/supervision';
      break;
    default:
      rel = '/';
  }
  return absoluteAppPath(rel);
}

/** @param {object} user @param {string} navId */
export function userCanAccessNav(user, navId) {
  const isAdmin = user.roles.includes('ROLE_ADMIN');
  const isManager = user.roles.includes('ROLE_MANAGER');
  const orgCount = user.organizations?.length ?? 0;
  const onboardingRequired = user.onboarding?.required;
  if (onboardingRequired) return false;
  if (navId === 'accountProfile' || navId === 'changePassword') {
    return true;
  }
  if (navId === 'adminSupervision') {
    return isAdmin;
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

export function authScreenFromPath() {
  const path = normalizePath(window.location.pathname);
  if (path === '/inscription') return 'register';
  if (path === '/mot-de-passe-oublie') return 'forgot';
  if (path === '/reinitialisation-mot-de-passe') return 'reset';
  if (path === '/invitation') return 'invitation';
  return 'login';
}
