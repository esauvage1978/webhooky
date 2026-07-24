# Monitoring Webhooky

Index de la documentation monitoring.

| Document | Contenu |
|----------|---------|
| [monitoring-implementation-plan.md](./monitoring-implementation-plan.md) | Plan Phase 1 (audit) |
| [architecture.md](./architecture.md) | Architecture livrée |
| [data-model.md](./data-model.md) | Tables & colonnes |
| [metrics-catalog.md](./metrics-catalog.md) | Catalogue métriques |
| [events-lifecycle.md](./events-lifecycle.md) | Statuts & retries |
| [alerts-and-incidents.md](./alerts-and-incidents.md) | Alertes / incidents |
| [cost-calculation.md](./cost-calculation.md) | Tarifs manuels |
| [deployment.md](./deployment.md) | Cron, worker Messenger |

## Accès UI

- Admin : `/admin/monitoring` (ROLE_ADMIN) — Tour de contrôle
- Client : `/monitoring` — scoped organisation courante

## APIs

- `/api/admin/monitoring/*`
- `/api/monitoring/*`
