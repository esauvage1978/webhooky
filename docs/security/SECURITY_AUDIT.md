# SECURITY_AUDIT — Webhooky

**Date :** 2026-07-20  
**Périmètre :** monorepo `webhooky` (builder + site vitrine)  
**Référentiels :** OWASP Top 10, OWASP API Top 10, ANSSI (bonnes pratiques), Symfony Security, Mozilla Observatory guidelines  

## Résumé exécutif

Webhooky est un SaaS d’orchestration de webhooks avec authentification session cookie, ingress public par jeton, connecteurs tiers et facturation Stripe. L’audit identifie un **niveau de risque global ÉLEVÉ** avant correctifs, principalement à cause d’un **relais e-mail public non authentifié**, de **secrets/env trackés**, de l’**absence de throttling login / rate-limit ingress branché**, de risques **SSRF** sur les appels sortants (IA + HTTP), et du **stockage/masquage incomplet des secrets de connecteurs**.

Points positifs majeurs : vérification signature Stripe, SQL Doctrine/DBAL paramétrée, pas d’upload fichier stocké, frontend React sans `dangerouslySetInnerHTML`, sourcemaps prod désactivées, isolation multi-tenant par organisation sur la majorité des API.

**Risque global avant correctifs :** ÉLEVÉ  
**Risque cible après Phase B (CRITIQUE/HAUT) :** MOYEN (résiduels : dette chiffrement historique, deps à mettre à jour, 2FA absent)

---

## Findings

### SEC-001 — Relais e-mail public sans authentification

| Champ | Valeur |
|-------|--------|
| **Criticité** | CRITIQUE |
| **Niveau de risque** | Critique |
| **Impact** | Abus du transport Mailjet/SMTP : spam, phishing, blacklist IP, coûts. Correspond à OWASP A01/A07, API1. |
| **Localisation** | `builder/src/Controller/NotificationWebhookController.php` — `POST /webhook/notification` ; `security.yaml` `^/webhook` → `PUBLIC_ACCESS` |
| **Solution** | Exiger un secret partagé (HMAC ou Bearer) ; refuser si secret vide en production. |
| **Correction proposée** | Header `X-Webhooky-Signature` (HMAC-SHA256 du body) ou `Authorization: Bearer` via `NOTIFICATION_WEBHOOK_SECRET`. |
| **Priorité** | P0 |

### SEC-002 — Mots de passe par défaut dans commandes console

| Champ | Valeur |
|-------|--------|
| **Criticité** | CRITIQUE |
| **Impact** | Compte admin/manager prévisible si la commande est exécutée sans override. |
| **Localisation** | `CreateAdminUserCommand.php`, `CreatePlatformManagerCommand.php` |
| **Solution** | Supprimer les defaults ; exiger `--password` ou prompt ; politique de complexité. |
| **Priorité** | P0 |

### SEC-003 — `APP_SECRET` / secrets dans fichiers env trackés

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Compromission sessions, signature CSRF interne, dérivation clé `SensitiveStringEncryptor`. |
| **Localisation** | `builder/.env.dev` (tracké), éventuellement valeurs dans historique git |
| **Solution** | Placeholders dans fichiers trackés ; secrets uniquement en `.env*.local` / secrets serveur ; rotation documentée. |
| **Priorité** | P0 |

### SEC-004 — Absence de login throttling

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Bruteforce `/api/login`. OWASP A07. |
| **Localisation** | `builder/config/packages/security.yaml` (firewall `main`) |
| **Solution** | Activer `login_throttling` Symfony. |
| **Priorité** | P0 |

### SEC-005 — Rate limiter ingress défini mais non branché

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | DoS / abus de quota sur `POST /webhook/form/{token}`. |
| **Localisation** | `framework.yaml` `rate_limiter.webhook_ingest` ; `FormWebhookIngressHandler.php` |
| **Solution** | Injecter `RateLimiterFactory $webhookIngestLimiter` et consommer avant traitement. |
| **Priorité** | P0 |

### SEC-006 — Secrets connecteurs en clair + masquage API partiel

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Fuite clés Mailjet/SMS/Telegram vers tout manager de l’org (API + UI JSON). |
| **Localisation** | `ServiceConnection`, `ApiServiceConnectionController::maskSensitiveConfigForApi` |
| **Solution** | Masquer toutes les clés sensibles ; chiffrer au repos via `SensitiveStringEncryptor`. |
| **Priorité** | P0 |

### SEC-007 — SSRF via `aiSettings.baseUrl` (Ollama)

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Manager peut pointer vers IP internes / metadata cloud. OWASP A10 SSRF. |
| **Localisation** | `AIActionService.php`, `ApiOrganizationController` (`aiSettings`) |
| **Solution** | `OutboundUrlGuard` : HTTPS, refus RFC1918/localhost/link-local/metadata. |
| **Priorité** | P0 |

### SEC-008 — SSRF / appels HTTP sortants non restreints

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Connecteurs HTTP/webhook vers réseaux privés. |
| **Localisation** | `IntegrationActionExecutor.php` |
| **Solution** | Même `OutboundUrlGuard` avant tout `HttpClient->request`. |
| **Priorité** | P0 |

### SEC-009 — Open redirect frontend (URLs Stripe / API)

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT |
| **Impact** | Si API compromise ou bug, `window.location.href` vers domaine arbitraire. |
| **Localisation** | `builder/assets/lib/billing.js`, UI facturation ; `urlSecurity.js` non utilisé et accepte `//evil` |
| **Solution** | Allowlist hôtes Stripe + same-origin ; corriger `urlSecurity.js`. |
| **Priorité** | P1 |

### SEC-010 — Fuite de détails d’erreur (Stripe / Mailer)

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Exposition de messages internes aux clients API. |
| **Localisation** | `ApiBillingController`, `NotificationWebhookController` |
| **Solution** | Message générique client ; détail en logs serveur. |
| **Priorité** | P1 |

### SEC-011 — Cookies de session peu explicites

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Dépendance aux défauts Symfony ; risque de mauvaise config reverse-proxy. |
| **Localisation** | `framework.yaml` `session: true` |
| **Solution** | `cookie_secure: auto`, `cookie_samesite: lax`, `cookie_httponly: true`. |
| **Priorité** | P1 |

### SEC-012 — CSRF logout / SPA sans token CSRF

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Logout CSRF limité ; login JSON sans CSRF (mitigé SameSite). |
| **Localisation** | `security.yaml` logout |
| **Solution** | Activer CSRF logout si compatible SPA ; documenter modèle session. |
| **Priorité** | P2 |

### SEC-013 — Politique mot de passe faible (min 8)

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Comptes faciles à bruteforcer. |
| **Localisation** | Contrôleurs register / reset |
| **Solution** | Min 12 + complexité basique. |
| **Priorité** | P2 |

### SEC-014 — Jeton ingress contact dans dépôt vitrine

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Abus du webhook de contact si jeton connu. |
| **Localisation** | `site_vitrine/src/config/site.ts`, `contact-webhook.php` fallback |
| **Solution** | Env only ; pas de jeton réel dans le code. |
| **Priorité** | P1 |

### SEC-015 — Prompt injection IA

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Contenu webhook peut influencer le LLM ; pas d’exfiltration système si Ollama local, mais abus métier. |
| **Localisation** | `BuiltinWorkflowActionExecutor`, `AIActionService` |
| **Solution** | Documenter risque ; limiter taille prompt ; ne jamais injecter secrets plateforme dans le contexte. |
| **Priorité** | P2 |

### SEC-016 — Payloads journaux (PII / secrets utilisateur)

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Données personnelles et éventuels secrets dans `rawBody` accessibles aux membres autorisés + clipboard. |
| **Localisation** | `FormWebhookLog`, UI `FormWebhooks.jsx` |
| **Solution** | Rétention documentée ; avertissement UI ; pas de log des secrets plateforme. |
| **Priorité** | P2 |

### SEC-017 — Jetons reset/invitation en query string

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Fuite via Referer / historique. |
| **Localisation** | `ResetPasswordForm.jsx`, `InvitationForm.jsx` |
| **Solution** | Fragment `#token=` ou échange one-time POST (évolution). |
| **Priorité** | P3 |

### SEC-018 — Absence de Voters Symfony centralisés

| Champ | Valeur |
|-------|--------|
| **Criticité** | FAIBLE |
| **Impact** | Risque de divergence des contrôles `canAccess*` dispersés. |
| **Localisation** | Controllers API |
| **Solution** | Voter pilote `OrganizationVoter` + migration progressive. |
| **Priorité** | P3 |

### SEC-019 — Compose local Postgres/Mailpit exposés

| Champ | Valeur |
|-------|--------|
| **Criticité** | FAIBLE (dev) |
| **Impact** | Exposition locale si override mal utilisé en prod. |
| **Localisation** | `compose.yaml`, `compose.override.yaml` |
| **Solution** | Documenter ; mdp non trivial ; ne jamais déployer override en prod. |
| **Priorité** | P3 |

### SEC-020 — Pas de CI sécurité / SAST

| Champ | Valeur |
|-------|--------|
| **Criticité** | FAIBLE |
| **Impact** | Régression silencieuse des advisories. |
| **Localisation** | Absence `.github/workflows` |
| **Solution** | Workflow `composer audit` + `npm audit` + tests sécurité. |
| **Priorité** | P1 |

### SEC-021 — Dépendances vulnérables (Composer / npm)

| Champ | Valeur |
|-------|--------|
| **Criticité** | HAUT (plusieurs CVE Symfony high/medium) |
| **Impact** | Voir `DEPENDENCY_AUDIT.md`. |
| **Solution** | `composer update` Symfony patch ; `npm audit fix` contrôlé. |
| **Priorité** | P0 |

### SEC-022 — Headers HTTP builder incomplets

| Champ | Valeur |
|-------|--------|
| **Criticité** | MOYEN |
| **Impact** | Clickjacking, MIME sniffing, absences CSP/HSTS côté app. |
| **Localisation** | `builder/public/.htaccess` |
| **Solution** | Aligner sur politique `SECURITY_HEADERS.md`. |
| **Priorité** | P1 |

### SEC-INFO — Contrôles positifs

| Contrôle | Statut |
|----------|--------|
| Signature Stripe webhook | OK |
| SQL injection (requêtes natives) | Paramétrées |
| Upload fichiers stockés | Absent |
| XSS DOM React (`dangerouslySetInnerHTML`) | Absent |
| Source maps production | Désactivées |
| Isolation org (majorité API) | Présente via `canAccess` / `IsGranted` |
| Chiffrement OAuth GSC | `SensitiveStringEncryptor` (sodium) |

---

## Cartographie OWASP Top 10 (aperçu)

| OWASP | Findings liés |
|-------|----------------|
| A01 Broken Access Control | SEC-001, SEC-009, SEC-018 |
| A02 Cryptographic Failures | SEC-003, SEC-006 |
| A03 Injection | SEC-015, deps CVE mime/mailer |
| A05 Security Misconfiguration | SEC-011, SEC-022, SEC-019 |
| A07 Identification Failures | SEC-004, SEC-013 |
| A09 Logging Failures | SEC-010, SEC-016 |
| A10 SSRF | SEC-007, SEC-008 |

## Cartographie OWASP API Top 10

| API | Findings |
|-----|----------|
| API1 BOLA / Object level | Mitigé partiellement ; voter à renforcer |
| API2 Broken Auth | SEC-004, SEC-012 |
| API4 Unrestricted Resource | SEC-005 |
| API8 Misconfig | SEC-001, SEC-022 |
| API10 Unsafe Consumption of APIs | SEC-007, SEC-008 |
