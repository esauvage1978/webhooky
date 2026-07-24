# Déploiement monitoring

## Migration

```bash
cd builder && php bin/console doctrine:migrations:migrate -n
```

## Cron recommandé

```cron
*/5 * * * * php bin/console app:monitoring:aggregate --hours=2
*/5 * * * * php bin/console app:monitoring:evaluate-alerts
15 1 * * * php bin/console app:monitoring:calculate-costs
30 2 * * * php bin/console app:monitoring:retention
```

## Worker Messenger

```bash
php bin/console messenger:consume async failed --time-limit=3600 --memory-limit=256M
```

Supervisor / systemd recommandé en production.
