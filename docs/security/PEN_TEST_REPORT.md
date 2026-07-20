# PEN_TEST_REPORT — Scénarios simulés

Méthode : revue rapide + scénarios manuels/automatisés. Ce n’est **pas** un pentest offensif externe signé.

| # | Attaque | Cible | Résultat observé / attendu | Statut |
|---|---------|-------|----------------------------|--------|
| PT-01 | Open mail relay | `POST /webhook/notification` sans secret | Avant correctif : envoi possible. Après : 401/403 | Corrigé |
| PT-02 | Bruteforce login | `POST /api/login` | Avant : illimité. Après : throttling Symfony | Corrigé |
| PT-03 | DoS ingress | Flood `/webhook/form/{token}` | Avant : pas de RL applicatif. Après : 429 | Corrigé |
| PT-04 | SSRF IA | `aiSettings.baseUrl=http://169.254.169.254` | Avant : requête possible. Après : rejet guard | Corrigé |
| PT-05 | SSRF HTTP webhook | URL `https://127.0.0.1` | Après guard : rejet | Corrigé |
| PT-06 | Stripe replay / signature | Body sans / mauvaise sig | 400 SignatureVerification | OK déjà |
| PT-07 | SQL injection | Params API / logs search | Requêtes paramétrées | OK |
| PT-08 | XSS stored logs | Payload `<script>` dans body | Affichage React texte échappé | OK |
| PT-09 | Open redirect | `checkoutUrl=https://evil.test` | Avant : redirect. Après : allowlist | Corrigé |
| PT-10 | IDOR org | Accès `/api/organizations/{id}` autre org | `canAccess` → 403 attendu | À revalider manuellement |
| PT-11 | Mass assignment plan | PATCH plan Free→Pro sans Stripe | Prod simulation off → bloqué | OK si `ALLOW_SUBSCRIPTION_SIMULATION=0` |
| PT-12 | CSRF logout | POST cross-site logout | SameSite=Lax + CSRF logout | Durci |
| PT-13 | Path traversal upload | N/A | Pas d’upload fichier | N/A |
| PT-14 | Command injection | Controllers | Pas de shell user-controlled | OK |
| PT-15 | JWT manipulation | N/A | Pas de JWT app | N/A |
| PT-16 | Secrets in API | GET service-connections | Avant : secrets non Mailjet en clair. Après : masqués | Corrigé |
| PT-17 | Header injection mail | Subject/to CRLF | Filtrer + update Symfony mime | Mitigé |
| PT-18 | Privilege escalation ROLE | Self-assign ROLE_ADMIN via API | Non exposé | OK (revue) |

## Scénarios manuels restants (ops)

1. Créer 2 organisations, vérifier IDOR sur form-webhooks et service-connections.
2. Tenter login 20× avec mauvais mot de passe — confirmer 429/lock.
3. Observer headers prod via `curl -I https://webhooky.builders`.
4. Vérifier qu’un jeton contact révoqué / régénéré invalide l’ancien.

## Limitations

- Pas de DAST OWASP ZAP automatisé dans ce chantier.
- Pas de test de charge saturant l’infra réelle.
- Race conditions billing non fuzzées.
