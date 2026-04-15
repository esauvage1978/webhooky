import { lazy, Suspense } from 'react';
import RouteFallback from '../components/feedback/RouteFallback.jsx';
import GoogleSearchConsolePanel from './GoogleSearchConsolePanel.jsx';

const ServiceConnections = lazy(() => import('./ServiceConnections.jsx'));

export default function Integrations({ user }) {
  return (
    <div className="users-shell org-section integrations-page">
      <GoogleSearchConsolePanel user={user} />
      <Suspense fallback={<RouteFallback />}>
        <ServiceConnections user={user} hubTitle="Intégrations" />
      </Suspense>
    </div>
  );
}
