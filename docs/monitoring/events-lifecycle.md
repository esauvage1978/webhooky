# Cycle de vie des événements

Statuts `FormWebhookLogStatus` :

| Statut | Signification |
|--------|---------------|
| `received` | Log créé |
| `parsed` | Parse OK |
| `sent` | Succès |
| `error` | Échec |
| `skipped` | Ignoré |
| `retry_scheduled` | Retry Messenger planifié |
| `dead_letter` | Échecs après max attempts |

Réponse ingress inclut `correlationId`.

Retry : erreurs 5xx / réseau / 429, max 3 (réglable `monitoring_setting.retry`).
