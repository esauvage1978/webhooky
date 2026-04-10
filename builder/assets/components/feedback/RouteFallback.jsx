import { memo } from 'react';

/**
 * Placeholder léger pendant le chargement d’un chunk lazy (code splitting).
 */
function RouteFallback() {
  return (
    <div className="admin-route-fallback" role="status" aria-live="polite">
      <div className="admin-spinner" aria-hidden />
      <p className="muted">Chargement du module…</p>
    </div>
  );
}

export default memo(RouteFallback);
