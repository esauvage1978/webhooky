export function WebhookDetailNotificationsPanel({ webhook }) {
  const d = webhook?.notificationDiagnostics;

  if (!d) {
    return (
      <section className="fw-detail-notify" aria-labelledby="fw-notify-h">
        <h3 id="fw-notify-h" className="fw-detail-section-title">
          Notifications e-mail
        </h3>
        <p className="muted small" style={{ marginTop: 0 }}>
          Rechargez la fiche pour afficher les préférences (récapitulatif en cas d’échec d’exécution).
        </p>
      </section>
    );
  }

  const srcLabel =
    d.notificationEmailSource === 'custom'
      ? 'Adresse personnalisée (champ du workflow)'
      : 'E-mail du créateur du workflow';

  return (
    <section className="fw-detail-notify" aria-labelledby="fw-notify-h">
      <h3 id="fw-notify-h" className="fw-detail-section-title">
        Notifications e-mail
      </h3>
      <p className="fw-detail-notify-lead">
        En cas d’échec du workflow, un récapitulatif peut être envoyé au destinataire configuré. Réglez les options dans{' '}
        <strong>Modifier</strong>.
      </p>

      {d.notifyOnError && d.recipientBlockedReason ? (
        <p className="fw-notify-warn">
          <strong>Destinataire :</strong> {d.recipientBlockedReason} — corrigez dans <strong>Modifier</strong> pour
          recevoir les récaps d’erreur.
        </p>
      ) : null}

      <dl className="fw-notify-dl">
        <dt>Destinataire pour les récaps d’erreur</dt>
        <dd className="mono">{d.effectiveRecipientEmail ?? '—'}</dd>
        <dt>Source</dt>
        <dd>{srcLabel}</dd>
        <dt>Notifier si erreur</dt>
        <dd>{d.notifyOnError ? 'Oui' : 'Non'}</dd>
        <dt>Envoi en cas d’échec (si destinataire OK)</dt>
        <dd>
          {d.willSendErrorEmailWhenRecipientOk
            ? 'Oui, lorsqu’au moins une action échoue'
            : d.notifyOnError
              ? 'Non tant que le destinataire est invalide'
              : 'Non (option désactivée)'}
        </dd>
      </dl>
    </section>
  );
}
