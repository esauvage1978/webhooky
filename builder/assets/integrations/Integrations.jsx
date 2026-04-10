import { lazy, Suspense } from 'react';
import RouteFallback from '../components/feedback/RouteFallback.jsx';

const ServiceConnections = lazy(() => import('./ServiceConnections.jsx'));

export default function Integrations({ user }) {
  return (
    <div className="users-shell org-section integrations-page">
      <Suspense fallback={<RouteFallback />}>
        <ServiceConnections user={user} hubTitle="Intégrations" />
      </Suspense>
    </div>
  );
}
