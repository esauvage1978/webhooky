# Calcul des coûts

Source des tarifs : **saisie manuelle** admin (`pricing_rule`).

```bash
php bin/console app:monitoring:calculate-costs [--day=YYYY-MM-DD]
```

Sans règle active : UI affiche « non configuré », aucun coût inventé.
