# Architecture monitoring

## Principes

1. Ingress prioritaire — métriques best-effort (`MonitoringMetricBuffer`).
2. Panne monitoring ≠ panne webhook (try/catch autour des writers).
3. Source de vérité runs : `form_webhook_log` / `form_webhook_action_log`.
4. Retries via Symfony Messenger (transport Doctrine `async`).

## Flux

```
Ingress → FormWebhookLog (+ correlation_id)
       → MetricBuffer (best-effort)
       → on action error retryable → RetryFormWebhookActionMessage (async)
Cron aggregate / evaluate-alerts / calculate-costs / retention
API overview → HealthScore + KPI + pipeline honnête
SPA admin/client
```

## Worker

```bash
php bin/console messenger:consume async -vv --time-limit=3600
```
