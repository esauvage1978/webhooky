# DEPENDENCY_AUDIT

**Date :** 2026-07-20 (mise à jour après `composer update "symfony/*"`)

## Composer (`builder/`)

Après montée de Symfony **7.4.14** (et composants associés) :

```
No security vulnerability advisories found.
```

Les CVE précédemment signalées (http-kernel HEAD bypass, mime CRLF, mailer, mailjet-mailer, cache, http-foundation IpUtils, etc.) sont corrigées par les patchs Symfony.

## npm — builder

| Advisory | Sévérité | Notes |
|----------|----------|-------|
| @babel/core / esbuild / vite / postcss | low–high | Surtout chaîne **dev** ; `npm audit fix` partiel. Éviter `--force` (breaking Vite). |

CI bloque uniquement `--audit-level=critical`.

## npm — site_vitrine

Après `npm audit fix` : **2 low** (esbuild via Astro, fix force = Astro 7 breaking). Accepté temporairement hors prod runtime SSG.

## Politique

- CI : `composer audit` bloquant ; `npm audit --audit-level=critical`.
- Revue mensuelle des advisories moderate.
