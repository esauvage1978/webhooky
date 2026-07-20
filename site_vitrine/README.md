# Site vitrine Webhooky (webhooky.fr)

Site marketing Astro (SSG) pour la plateforme Webhooky.

## Commandes

```bash
npm install
npm run dev
npm run test
npm run build
npm run preview
```

## Configuration

Voir `.env.example` pour :

- l’URL du webhook de contact ;
- les variables `PUBLIC_LEGAL_*` (identité éditeur / hébergeur).

Les tarifs et statuts de fonctionnalités sont centralisés dans `src/config/`.  
Synchronisation des prix avec l’app : `docs/SYNC_PRICING.md`.

## Déploiement

1. Renseigner les variables légales et le webhook contact.
2. `npm run build`
3. Publier le contenu de `dist/` (inclut `contact-webhook.php`, `.htaccess`, `robots.txt`).
4. Définir `CONTACT_WEBHOOK_FORWARD_URL` côté serveur si besoin.

Application SaaS : https://webhooky.builders
