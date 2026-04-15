import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import RouteFallback from '../components/feedback/RouteFallback.jsx';
import Tabs from '../components/ui/Tabs.jsx';
import GoogleSearchConsolePanel from './GoogleSearchConsolePanel.jsx';

const ServiceConnections = lazy(() => import('./ServiceConnections.jsx'));

export default function Integrations({ user }) {
  const initialTab = useMemo(() => {
    const p = new URLSearchParams(window.location.search);
    const explicit = (p.get('tab') || '').trim();
    if (explicit === 'gsc') return 'gsc';
    if (explicit === 'connectors') return 'connectors';
    if (p.get('gsc') === 'connected') return 'gsc';
    return 'connectors';
  }, []);

  const [activeTab, setActiveTab] = useState(initialTab);

  const syncUrl = useCallback((nextId) => {
    const p = new URLSearchParams(window.location.search);
    p.set('tab', nextId);
    const qs = p.toString();
    const nextUrl = `${window.location.pathname}${qs ? `?${qs}` : ''}`;
    window.history.replaceState({}, '', nextUrl);
  }, []);

  useEffect(() => {
    syncUrl(activeTab);
  }, [activeTab, syncUrl]);

  return (
    <div className="users-shell org-section integrations-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-plug" aria-hidden />
            <span>Intégrations</span>
          </h1>
          <p className="users-hero-sub muted">Connecteurs tiers et Google Search Console, par organisation.</p>
        </div>
      </header>

      <div className="content-card content-card--integrations-wide">
        <Tabs
          ariaLabel="Onglets intégrations"
          activeId={activeTab}
          onChange={setActiveTab}
          items={[
            { id: 'connectors', label: 'Connecteurs' },
            { id: 'gsc', label: 'Google Search Console' },
          ]}
        />

        <div
          id="panel-connectors"
          role="tabpanel"
          aria-labelledby="tab-connectors"
          hidden={activeTab !== 'connectors'}
        >
          {activeTab === 'connectors' ? (
            <Suspense fallback={<RouteFallback />}>
              <ServiceConnections user={user} embeddedInTabs />
            </Suspense>
          ) : null}
        </div>

        <div id="panel-gsc" role="tabpanel" aria-labelledby="tab-gsc" hidden={activeTab !== 'gsc'}>
          {activeTab === 'gsc' ? <GoogleSearchConsolePanel user={user} /> : null}
        </div>
      </div>
    </div>
  );
}
