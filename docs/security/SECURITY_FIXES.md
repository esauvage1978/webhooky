# SECURITY_FIXES — Suivi des correctifs

| ID | Criticité | Correctif | Zone | Statut |
|----|-----------|-----------|------|--------|
| SEC-001 | CRITIQUE | Secret HMAC/Bearer sur `/webhook/notification` | `NotificationWebhookController` | **fait** |
| SEC-002 | CRITIQUE | Pas de password défaut CLI | Commands Create* | **fait** |
| SEC-003 | HAUT | Placeholders env trackés | `.env.dev` / `.env.prod` | **fait** |
| SEC-004 | HAUT | `login_throttling` | `security.yaml` | **fait** |
| SEC-005 | HAUT | Brancher `webhook_ingest` | `FormWebhookIngressHandler` | **fait** |
| SEC-006 | HAUT | Masquage + chiffrement secrets connecteurs | `ServiceConnectionSecretHelper` | **fait** |
| SEC-007 | HAUT | `OutboundUrlGuard` IA | `AIActionService` | **fait** |
| SEC-008 | HAUT | `OutboundUrlGuard` HTTP | `IntegrationActionExecutor` | **fait** |
| SEC-009 | HAUT | Allowlist URLs Stripe + `urlSecurity` | assets | **fait** |
| SEC-010 | MOYEN | Erreurs API génériques | Billing / Notification | **fait** |
| SEC-011 | MOYEN | Cookies session explicites | `framework.yaml` | **fait** |
| SEC-012 | MOYEN | CSRF logout | SPA GET logout — reporté ; SameSite documenté | **documenté** |
| SEC-013 | MOYEN | Politique mot de passe min 12 | Register / Reset / Invite / Me | **fait** |
| SEC-014 | MOYEN | Jeton contact hors code | site_vitrine | **fait** |
| SEC-015 | MOYEN | Limite taille prompt IA | `AIActionService` | **fait** |
| SEC-016 | MOYEN | Avertissement logs PII | Architecture / checklist | **documenté** |
| SEC-017 | MOYEN | Jetons hors query | Évolution | **reporté** P3 |
| SEC-018 | FAIBLE | `OrganizationVoter` pilote | `Security/Voter` | **fait** |
| SEC-019 | FAIBLE | Compose / doc | compose + checklist | **documenté** |
| SEC-020 | FAIBLE | CI security | `.github/workflows/security.yml` | **fait** |
| SEC-021 | HAUT | Update deps Symfony | `composer update symfony/*` | **fait** (audit clean) |
| SEC-022 | MOYEN | Headers Apache builder | `.htaccess` | **fait** |

## Ops post-déploiement

1. Définir `NOTIFICATION_WEBHOOK_SECRET` en prod (sinon 401).
2. S’assurer que `APP_SECRET` local/prod n’est **pas** la valeur placeholder de `.env.dev`.
3. Régénérer le jeton contact vitrine si l’ancien était exposé ; configurer `CONTACT_WEBHOOK_FORWARD_URL`.
4. Rotation `APP_SECRET` uniquement avec plan de re-chiffrement OAuth (casse `SensitiveStringEncryptor`).
