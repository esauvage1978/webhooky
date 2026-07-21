# Rapport final — refonte site vitrine Webhooky

**Date :** 2026-07-21  
**Commits de référence :** `827745c` (refonte), `e42d839` (sécurité contact), + compléments nav / OG / e2e

## Verdict

La demande de refonte a été **implémentée dans le dépôt**. Les pages, la config centralisée, le SEO, le consentement cookies, le formulaire contact sécurisé et les CTAs builders sont en place.

**Bloquant commercialisation hors code :** identité légale (`PUBLIC_LEGAL_*`), validation juridique CGU/CGV/privacy, et **déploiement** de `site_vitrine/dist/` sur `webhooky.fr` (voir [DEPLOYMENT_GAP.md](./DEPLOYMENT_GAP.md)).

---

## Implémenté (code)

| Exigence | État |
|----------|------|
| Positionnement hors Mailjet-only | Accueil + pages produit |
| Arborescence min. (`/`, fonctionnalités, cas-usage, intégrations, tarifs, doc, sécurité, à-propos, contact, légales) | `site_vitrine/src/pages/` |
| Pages optionnelles | `/roadmap/`, `/blog/`, `/integrations/mailjet/`, `/solutions/{developpeurs,agences,saas}/` |
| Config centralisée + validation Zod | `src/config/` |
| Statuts Disponible / Bêta / Prochainement | `status.ts` + `StatusBadge` |
| Tarifs Free 100 / Starter 9€ / Pro 29€ | `pricing.ts` + [SYNC_PRICING.md](./SYNC_PRICING.md) |
| CTAs inscription / connexion | `site.ts` → builders |
| Contact sécurisé | `ContactForm` + `contact-webhook.php` |
| Cookies / GA après consentement | `CookieConsent` + `GoogleAnalytics` |
| SEO (meta, sitemap, robots, JSON-LD, OG, 404, trailing slash) | Layout + Astro sitemap |
| Headers sécu | `.htaccess` |
| Tests unitaires Vitest | `npm test` (9 tests) |
| Menu **Produit** (dropdown header) | `SiteHeader.astro` + `navigation.ts` |
| OG image PNG 1200×630 | `/og-default.png` |
| Tests e2e Playwright | `npm run test:e2e` (7 parcours critiques) |

Pages volontairement absentes (pas de contenu réel) : `/statut/`, `/comparatifs/`.

---

## À configurer (ops / env)

| Élément | Action |
|---------|--------|
| Déploiement `webhooky.fr` | Publier `dist/` (live encore sur l’ancienne vitrine — [DEPLOYMENT_GAP.md](./DEPLOYMENT_GAP.md)) |
| `CONTACT_WEBHOOK_FORWARD_URL` | Variable serveur Apache/PHP (relais contact) |
| `PUBLIC_LEGAL_*` | Raison sociale, adresse, SIREN/SIRET, hébergeur, directeur de publication, etc. |
| GA | ID déjà présent (`G-4247L6EFFF`) — activer uniquement après consentement (déjà géré en front) |

---

## Juridique / conformité (hors agent)

| Point | État |
|-------|------|
| Mentions légales / CGU / CGV / privacy | Pages présentes ; textes à **valider par un avocat** |
| Identité éditeur | Variables `PUBLIC_LEGAL_*` encore vides → placeholders jusqu’à fourniture |
| Cookies | Bannière + stockage consentement v2 |
| Pas de page statut service | Non livrée (pas de status page réelle) |

---

## Builders (rappel)

- Inscription : `https://webhooky.builders/inscription`
- Connexion : `https://webhooky.builders/`
- Tarifs vitrine alignés manuellement sur le catalogue builder (sync auto non automatisée — documentée)

---

## Compléments livrés le 2026-07-21

1. Audit live vs repo → écart déploiement documenté  
2. Dropdown « Produit » dans le header  
3. OG PNG (`SOCIAL_OG_IMAGE = /og-default.png`)  
4. Playwright : accueil→inscription, tarifs, cookies, 404, menu Produit, liens juridiques, doc→app  

## Commandes de vérif

```bash
cd site_vitrine
npm test
npm run build
npm run test:e2e
```

## P3 restants (non bloquants code)

- ESLint dédié `site_vitrine` (stack Astro minimale)
- Sync tarifs automatique PHP ↔ TS
- Page `/statut/` si un monitoring réel est mis en place
- Optimisation poids éventuelle de `og-default.png`
