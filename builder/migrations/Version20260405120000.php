<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réparation : colonnes enum Organization remplies par '' ou valeurs obsolètes après schema:update / imports,
 * ce qui casse l’hydratation Doctrine vers SubscriptionPlan / BillingStatus.
 */
final class Version20260405120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise subscription_plan et billing_status (valeurs vides → free / active, legacy → nouveaux forfaits).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE organization SET subscription_plan = 'free' WHERE subscription_plan = '' OR subscription_plan IS NULL");
        $this->addSql("UPDATE organization SET billing_status = 'active' WHERE billing_status = '' OR billing_status IS NULL");

        $this->addSql("UPDATE organization SET subscription_plan = 'free' WHERE subscription_plan IN ('trial')");
        $this->addSql("UPDATE organization SET subscription_plan = 'starter' WHERE subscription_plan IN ('single_webhook')");
        $this->addSql("UPDATE organization SET subscription_plan = 'pro' WHERE subscription_plan IN ('unlimited')");

        $this->addSql("UPDATE organization SET subscription_plan = 'pro' WHERE subscription_plan NOT IN ('free', 'starter', 'pro')");

        $this->addSql("UPDATE organization SET billing_status = 'active' WHERE billing_status NOT IN ('trialing', 'active', 'past_due', 'canceled', 'incomplete')");

        $this->addSql('UPDATE organization SET events_extra_quota = 0 WHERE subscription_plan = \'free\'');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
