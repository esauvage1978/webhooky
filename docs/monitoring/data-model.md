# Modèle de données monitoring

## Enrichissements logs

- `form_webhook_log.correlation_id`, `http_status_response`, `quota_units_consumed`, `attempt_count`
- `form_webhook_action_log.attempt`

## Tables

| Table | Rôle |
|-------|------|
| `monitoring_metric_agg` | Agrégats minute/heure/jour |
| `monitoring_setting` | Seuils / config JSON |
| `monitoring_alert` | Alertes dédupliquées (fingerprint) |
| `monitoring_incident` | Regroupement alertes |
| `pricing_rule` | Tarifs unitaires manuels |
| `monitoring_cost_entry` | Coûts journaliers estimés |
| `messenger_messages` | File Doctrine Messenger |

## Rétention payloads (par plan)

| Plan | Payloads | Logs |
|------|----------|------|
| Free | 7 j | 30 j |
| Starter | 30 j | 90 j |
| Pro | 90 j | 365 j |
