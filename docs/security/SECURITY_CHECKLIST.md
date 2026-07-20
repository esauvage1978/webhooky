# SECURITY_CHECKLIST — Release / exploitation

## Avant mise en production

- [ ] `APP_SECRET` unique, fort, **uniquement** dans secrets serveur / `.env.prod.local` (jamais dans git)
- [ ] `NOTIFICATION_WEBHOOK_SECRET` défini et non vide ; appelants configurés avec signature
- [ ] `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PUBLISHABLE_KEY` en secrets serveur
- [ ] `DATABASE_URL` / `MAILER_DSN` hors dépôt
- [ ] `ALLOW_SUBSCRIPTION_SIMULATION=0` (ou absent) en production
- [ ] `APP_ENV=prod` et `APP_DEBUG=0`
- [ ] HTTPS forcé (reverse-proxy / Apache)
- [ ] Headers de sécurité actifs (voir SECURITY_HEADERS.md)
- [ ] Webhook contact vitrine : `CONTACT_WEBHOOK_FORWARD_URL` serveur only
- [ ] Variables `PUBLIC_LEGAL_*` renseignées si pages légales publiées
- [ ] Compte admin créé avec mot de passe fort (commande sans default)
- [ ] Rotation planifiée si un secret a été commit historiquement

## Après déploiement

- [ ] Vérifier `POST /webhook/notification` → 401/403 sans secret
- [ ] Vérifier login : après N échecs, throttling actif
- [ ] Vérifier ingress form : 429 sous charge excessive
- [ ] Vérifier Stripe webhook : 400 si signature invalide
- [ ] Scanner headers (Mozilla Observatory / securityheaders.com)
- [ ] `composer audit` / `npm audit` sans CRITICAL non accepté
- [ ] Sauvegardes DB chiffrées / accès restreint

## Exploitation continue

- [ ] Revue trimestrielle des connecteurs et membres org
- [ ] Surveillance logs erreurs (sans secrets)
- [ ] Mise à jour Symfony patch dans les 7 jours suivant une CVE high
- [ ] Revue des `aiSettings.baseUrl` organisations
- [ ] Test de restauration backup

## Interdits

- [ ] Ne jamais committer `.env*.local` ni clés Stripe/Mailjet
- [ ] Ne jamais exposer `compose.override.yaml` (ports DB) sur Internet
- [ ] Ne jamais activer le profiler en prod
- [ ] Ne jamais logger mots de passe, JWT, corps complets de cartes bancaires
