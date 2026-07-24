export function LastExecutionVerifiedSwitch({ verified, compact = false }) {
  const ok = verified === true;
  const none = verified == null;
  const title = ok
    ? 'Dernière exécution : sans erreur enregistrée (vérifié).'
    : none
      ? 'Aucune exécution enregistrée pour l’instant.'
      : 'Dernière exécution : erreur — à vérifier.';
  const cls = ok ? 'fw-last-exec-switch--ok' : none ? 'fw-last-exec-switch--none' : 'fw-last-exec-switch--bad';
  return (
    <span className={`fw-last-exec-switch ${cls}`} role="status" title={title}>
      <span className="fw-last-exec-switch__track" aria-hidden="true">
        <span className="fw-last-exec-switch__thumb" />
      </span>
      {compact ? (
        <span className="fw-last-exec-switch__compact-label">{ok ? 'Vérifié' : none ? '—' : 'À vérifier'}</span>
      ) : (
        <span className="fw-last-exec-switch__labels">
          <span className={ok ? 'is-em' : 'muted'}>Vérifié</span>
          <span className="fw-last-exec-switch__sep" aria-hidden="true">
            /
          </span>
          <span className={!ok ? 'is-em' : 'muted'}>À vérifier</span>
        </span>
      )}
    </span>
  );
}
