# Alertes et incidents

Commande : `php bin/console app:monitoring:evaluate-alerts`

Règles initiales :

- taux d’erreur > 15 % sur ≥ 5 runs (15 min) → critique
- rate limit > 50 / h → warning
- dead letter > 0 / h → critique

Déduplication : fingerprint `sha1(code|domain|org|heure)`.

≥ 2 alertes critiques ouvertes → incident plateforme.
