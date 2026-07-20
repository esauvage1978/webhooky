# Documentation sécurité Webhooky

Index des livrables d’audit et de durcissement (juillet 2026).

| Document | Description |
|----------|-------------|
| [SECURITY_AUDIT.md](./SECURITY_AUDIT.md) | Inventaire des findings (CRITIQUE → INFORMATION) |
| [SECURITY_FIXES.md](./SECURITY_FIXES.md) | Mapping finding → correctif et statut |
| [SECURITY_CHECKLIST.md](./SECURITY_CHECKLIST.md) | Checklist release / exploitation |
| [SECURITY_HEADERS.md](./SECURITY_HEADERS.md) | Politique d’en-têtes HTTP |
| [SECURITY_ARCHITECTURE.md](./SECURITY_ARCHITECTURE.md) | Boundaries de confiance et flux |
| [PEN_TEST_REPORT.md](./PEN_TEST_REPORT.md) | Scénarios de pentest simulés |
| [DEPENDENCY_AUDIT.md](./DEPENDENCY_AUDIT.md) | Composer / npm audit |

**Périmètre :** `builder/` (Symfony + React), `site_vitrine/` (Astro), compose Postgres/Mailpit local.

**Hors périmètre infra :** pas de Dockerfile applicatif ni Nginx versionnés — recommandations Apache/hébergeur documentées.
