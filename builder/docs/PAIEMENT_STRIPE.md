# Mise en œuvre du paiement (Stripe) — Webhooky

Ce document décrit **ce qui est déjà en place** dans l’application (modèle d’abonnement par organisation, limites de webhooks, API) et **les étapes concrètes** pour brancher un prestataire de paiement — en pratique **Stripe** — afin que les forfaits deviennent **réellement payants**.

## 1. Modèle métier actuel (résumé)

| Forfait (`plan`) | Prix indicatif | Webhooks | Événements inclus | Dépassement |
|------------------|----------------|----------|-------------------|-------------|
| `free`           | 0 €            | **1**    | **100**           | **Non** — blocage + upgrade obligatoire |
| `starter`        | **9 €**/mois   | illimité | **5 000**         | Packs +1 000 (2 €) ou +5 000 (8 €) — voir `SubscriptionPlanCatalog` |
| `pro`            | **29 €**/mois  | illimité | **50 000**        | Packs +10 000 (5 €) ou +50 000 (20 €) |

- Compteurs sur `Organization` : `events_consumed` (ingress réels), `events_extra_quota` (volume acheté via packs).
- L’abonnement est **rattaché à l’entité `Organization`** (pas à l’utilisateur).
- Les champs prévus pour Stripe : `stripe_customer_id`, `stripe_subscription_id`, `subscription_current_period_end`, `billing_status`.
- **Ingress** : HTTP **402** si abonnement inactif (`subscription_inactive`) ou quota d’événements épuisé (`events_quota_exceeded`).
- Grille et packs exposés en lecture sur **`GET /api/subscription/plans`**.

> Les montants sont définis dans `App\Subscription\SubscriptionPlanCatalog`. **Stripe** matérialise les prix réels (`Price` récurrents + éventuels add-ons / metered billing pour les packs).

---

## 2. Ce que vous devez préparer côté Stripe

### 2.1 Compte et mode test

1. Créer un compte sur [https://stripe.com](https://stripe.com).
2. Récupérer les clés **test** : *Developers → API keys*  
   - `pk_test_…` (publique, côté frontend si vous utilisez Stripe.js / Checkout).
   - `sk_test_…` (secrète, **uniquement** serveur Symfony, variable d’environnement).

### 2.2 Produits et prix récurrents

Créer **deux abonnements** en facturation **récurrente mensuelle** :

1. **Starter** — **9 €**/mois (5 000 événements inclus — politique de comptage à synchroniser avec `events_consumed`).
2. **Pro** — **29 €**/mois (50 000 événements inclus).

Les **packs d’événements** peuvent être des `Price` Stripe distincts (paiement unique ou add-on), ou une facturation à l’usage (metered) ; alignez les montants sur `SubscriptionPlanCatalog::eventPacks()`.

Pour chaque prix, noter l’identifiant **`price_…`** (Price ID). Vous en aurez besoin dans Symfony pour ouvrir une session **Checkout** ou créer un **Subscription** via l’API.

### 2.3 Webhook Stripe → votre application

1. *Developers → Webhooks → Add endpoint*.
2. URL du endpoint (exemple) : `https://votredomaine.fr/api/stripe/webhook`  
   - À implémenter dans Symfony (voir section 4).
3. Événements utiles (minimum viable) :
   - `checkout.session.completed` — première souscription réussie.
   - `customer.subscription.updated` — changement de statut, fin de période, etc.
   - `customer.subscription.deleted` — résiliation.
   - `invoice.paid` — renouvellement OK (optionnel si vous vous basez surtout sur `subscription.updated`).
   - `invoice.payment_failed` — pour passer en `past_due` côté organisation.

4. Noter le **Signing secret** du webhook (`whsec_…`) pour vérifier la signature des payloads.

### 2.4 Portail client (optionnel mais recommandé)

Stripe **Billing Portal** permet aux clients de mettre à jour la carte, voir les factures, annuler l’abonnement. À activer dans *Settings → Billing → Customer portal* et à lier à votre `stripe_customer_id`.

---

## 3. Variables d’environnement Symfony (exemple)

À ajouter dans `.env.local` (ne jamais commiter les secrets) :

```env
STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_PRICE_SINGLE_WEBHOOK=price_xxx
STRIPE_PRICE_UNLIMITED=price_yyy
# URL publique de l’app (déjà souvent présente pour les e-mails)
APP_PUBLIC_URL=https://webhooky.builders
```

---

## 4. Implémentation technique dans ce projet (à faire)

Le dépôt contient déjà la **structure métier**. Il manque la couche **Stripe** proprement dite :

### 4.1 Dépendance PHP

```bash
composer require stripe/stripe-php
```

### 4.2 Flux Checkout (recommandé pour démarrer)

1. **Endpoint** `POST /api/billing/checkout` (authentifié `ROLE_MANAGER` ou `ROLE_ADMIN` sur l’organisation ciblée) :
   - Corps JSON : `{ "plan": "starter" | "pro" }` (et packs via `purchaseEventPack`, voir l’API).
   - Côté serveur : créer ou retrouver un **Customer** Stripe (`Organization.stripeCustomerId`), puis `Session::create` en mode `subscription` avec le bon `price_…`.
   - Retour JSON : `{ "checkoutUrl": "https://checkout.stripe.com/..." }`.
2. Le **frontend** redirige l’utilisateur vers `checkoutUrl`.
3. Après paiement, Stripe envoie `checkout.session.completed` à votre **webhook interne**.

### 4.3 Webhook interne Symfony

1. Nouvelle route **publique** `POST /api/stripe/webhook` (sans session utilisateur) :  
   - Lire le corps brut (`Request::getContent()`).
   - Vérifier la signature avec `\Stripe\Webhook::constructEvent` et `STRIPE_WEBHOOK_SECRET`.
2. Selon `type` de l’événement :
   - Récupérer `organization` via metadata (`metadata.organization_id` à poser lors de la création de la Session) ou via `client_reference_id`.
   - Mettre à jour l’entité `Organization` :
     - `setStripeCustomerId()`, `setStripeSubscriptionId()`
     - `setSubscriptionPlan()` → `starter` ou `pro` selon le `price` souscrit ; mettre à jour `events_extra_quota` si achat de pack.
     - `setBillingStatus()` → mapping depuis `subscription.status` Stripe (`active`, `past_due`, `canceled`, etc.).
     - `setSubscriptionCurrentPeriodEnd()` depuis `current_period_end` de la souscription Stripe (timestamp Unix → `DateTimeImmutable`).
3. **Ne pas** faire confiance au frontend pour activer le forfait : toute mise à jour d’abonnement payant doit provenir du **webhook** (ou d’une vérification serveur immédiate après checkout si vous ajoutez un flux de secours).

### 4.4 Remplacer le « mock » actuel

Le tableau de bord peut appeler `PATCH /api/organizations/{id}/subscription` avec `{ "plan": "starter" }` / `"pro"` ou `{ "purchaseEventPack": "starter_1k" }` etc. pour **simuler** l’usage — **sans encaissement réel** tant que Stripe n’est pas branché.

Pour la production :

- Gardez ce endpoint réservé aux **administrateurs support** ou supprimez-le / sécurisez-le fortement.
- Le parcours normal utilisateur doit être : **bouton → Flux Checkout Stripe → webhook → mise à jour `Organization`**.

### 4.5 Conformité RGPD & CGV

- Pages **CGV**, **politique de confidentialité**, mention du droit de rétractation si B2C.
- Traitement des données : Stripe agit en tant que sous-traitant ; documenter dans votre registre.
- Factures : disponibles dans Stripe ; possibilité de les relier à votre comptabilité.

### 4.6 Tests

- Utiliser les **cartes test** Stripe (ex. `4242 4242 4242 4242`).
- Tester résiliation, échec de paiement, renouvellement, avec le **Stripe CLI** pour rejouer les webhooks en local :
  ```bash
  stripe listen --forward-to localhost:8000/api/stripe/webhook
  ```

---

## 5. Cohérence avec le code existant

- **Nouvelles organisations** : forfait par défaut **`free`** (`Organization`), avec `events_consumed` / `events_extra_quota`.
- Migrations : voir `Version20260405000000` pour les compteurs d’événements et le mapping des anciens identifiants de plan vers `free` / `starter` / `pro`.
- **Statut `past_due`** : aujourd’hui l’accès est **bloqué** (pas de période de grâce). Vous pouvez assouplir la logique dans `SubscriptionEntitlementService::isPaidSubscriptionEntitled`.

---

## 6. Checklist avant mise en production

- [ ] Compte Stripe **live**, clés **live** dans l’environnement de prod.
- [ ] Webhook configuré en **live** avec le bon `whsec_…`.
- [ ] Prix et produits **live** alignés avec la grille commerciale.
- [ ] HTTPS valide sur l’URL du webhook.
- [ ] Désactivation ou restriction du `PATCH …/subscription` non Stripe.
- [ ] Tests E2E : souscription, renouvellement, défaut de paiement, résiliation.
- [ ] Monitoring des erreurs webhook (logs Symfony + dashboard Stripe).

---

## 7. Références utiles

- Documentation Stripe — Checkout : [https://stripe.com/docs/payments/checkout](https://stripe.com/docs/payments/checkout)
- Souscriptions : [https://stripe.com/docs/billing/subscriptions/overview](https://stripe.com/docs/billing/subscriptions/overview)
- Webhooks : [https://stripe.com/docs/webhooks](https://stripe.com/docs/webhooks)

En résumé : le modèle de données et les règles d’accès sont **déjà branchés** sur l’organisation ; pour un paiement **fonctionnel**, il vous reste principalement à **créer les prix Stripe**, **exposer Checkout** depuis Symfony et **appliquer les événements du webhook Stripe** sur les champs `Organization` décrits ci-dessus.
