# Écart déploiement — webhooky.fr vs dépôt

**Date :** 2026-07-21  
**Commit de référence vitrine :** `827745c` (+ compléments nav/OG/e2e)

## Méthode

Comparaison de `https://webhooky.fr/` (fetch live) avec le code source `site_vitrine/` et les titres/routes du build Astro.

## Résultat

| Critère | Live webhooky.fr | Dépôt / build |
|---------|------------------|---------------|
| Title / H1 | Ancien positionnement Mailjet (« Un webhook, plusieurs actions — dont vos e-mails Mailjet ») | « Centralisez vos webhooks… » |
| CTA principal | « Ouvrir le tableau de bord » → builders racine | « Créer un compte » → `/inscription` |
| `/fonctionnalites/` | **404** | Page complète |
| `/securite/` | Timeout / indisponible | Page complète |
| Menu Produit | Absent | Dropdown Produit (après complément) |

**Conclusion :** le site public **n’est pas à jour**. Le code de refonte est dans le dépôt mais **n’a pas été déployé** (ou partiellement) sur l’hébergement de `webhooky.fr`.

## Action requise

1. `cd site_vitrine && npm ci && npm run build`
2. Publier le contenu de `dist/` à la racine web (conserver `contact-webhook.php`, `.htaccess`, `.well-known/`)
3. Configurer `CONTACT_WEBHOOK_FORWARD_URL` côté serveur
4. Vérifier après déploiement : `/`, `/fonctionnalites/`, `/tarifs/`, `/securite/` → 200
