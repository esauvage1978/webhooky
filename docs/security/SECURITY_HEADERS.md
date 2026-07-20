# SECURITY_HEADERS

## Objectif

Aligner builder et vitrine sur une politique d’en-têtes compatible SPA Symfony + analytics conditionnelle.

## Vitrine (`site_vitrine/public/.htaccess`)

Déjà en place (référence) :

| Header | Valeur |
|--------|--------|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Content-Security-Policy` | `default-src 'self'` + GA/GTM conditionnels + `'unsafe-inline'` scripts (contrainte Astro inline) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (si HTTPS) |

## Builder (`builder/public/.htaccess`)

Politique cible appliquée :

| Header | Valeur | Notes |
|--------|--------|-------|
| `X-Content-Type-Options` | `nosniff` | |
| `X-Frame-Options` | `SAMEORIGIN` | Anti-clickjacking (complété par CSP `frame-ancestors`) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | |
| `Content-Security-Policy` | Voir ci-dessous | SPA Vite + API same-origin |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Uniquement derrière HTTPS |

### CSP builder (pragmatique)

```
default-src 'self';
base-uri 'self';
form-action 'self';
frame-ancestors 'self';
object-src 'none';
img-src 'self' data: https:;
font-src 'self' data:;
style-src 'self' 'unsafe-inline';
script-src 'self' 'unsafe-inline';
connect-src 'self' https://api.stripe.com https://checkout.stripe.com https://m.stripe.network;
upgrade-insecure-requests
```

`'unsafe-inline'` pour scripts/styles est un compromis SPA actuel ; évolution recommandée : nonces Symfony / hashes Vite.

## Cache

- Pages HTML app : `no-store` recommandé pour réponses authentifiées (géré par Symfony pour API JSON).
- Assets `/build/` : cache long + hash de fichier Vite.

## Cross-Origin

- Pas de CORS credentials wildcard sur l’API session.
- Ingress webhook : allowlist d’origines plateforme (option) sans `Allow-Credentials`.
