# Catalogue métriques

| Clé | Unité |
|-----|-------|
| `webhook.received.count` | count |
| `webhook.run.success.count` | count |
| `webhook.run.error.count` | count |
| `webhook.run.skipped.count` | count |
| `webhook.processing.duration_ms` | ms |
| `webhook.action.success.count` | count |
| `webhook.action.error.count` | count |
| `webhook.action.duration_ms` | ms |
| `webhook.action.http_status` | count (dims bucket) |
| `webhook.rate_limited.count` | count |
| `webhook.retry_scheduled.count` | count |
| `webhook.dead_letter.count` | count |

Coûts SMS/email : uniquement si `pricing_rule` actif.
