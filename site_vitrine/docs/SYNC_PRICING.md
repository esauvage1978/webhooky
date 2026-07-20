# Synchronisation des tarifs vitrine ↔ application

## Source applicative (référence)

`builder/src/Subscription/SubscriptionPlanCatalog.php`

## Source vitrine

`site_vitrine/src/config/pricing.ts`

Les constantes doivent rester identiques :

| Clé | Valeur |
|-----|--------|
| Free events | 100 |
| Starter price | 9 € HT |
| Starter events | 5 000 |
| Pro price | 29 € HT |
| Pro events | 50 000 |
| Packs | starter_1k/5k, pro_10k/50k |

## Contrôles

- Validation au build Astro (`src/config/validate.ts`)
- Tests Vitest (`src/config/validate.test.ts`)

Toute modification de grille doit mettre à jour **les deux** fichiers dans le même commit.
