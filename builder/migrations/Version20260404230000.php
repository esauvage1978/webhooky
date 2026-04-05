<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnement par organisation (essai 15 j / 1 webhook, forfaits payants, champs Stripe).';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE organization ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP --(DC2Type:datetime_immutable)');
            $this->addSql('ALTER TABLE organization ADD COLUMN subscription_plan VARCHAR(32) NOT NULL DEFAULT \'unlimited\'');
            $this->addSql('ALTER TABLE organization ADD COLUMN trial_ends_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)');
            $this->addSql('ALTER TABLE organization ADD COLUMN billing_status VARCHAR(32) NOT NULL DEFAULT \'active\'');
            $this->addSql('ALTER TABLE organization ADD COLUMN subscription_current_period_end DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)');
            $this->addSql('ALTER TABLE organization ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('ALTER TABLE organization ADD COLUMN stripe_subscription_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('UPDATE organization SET subscription_plan = \'unlimited\', billing_status = \'active\', trial_ends_at = NULL');
        } else {
            $this->addSql('ALTER TABLE organization ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE organization ADD subscription_plan VARCHAR(32) NOT NULL DEFAULT \'unlimited\'');
            $this->addSql('ALTER TABLE organization ADD trial_ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE organization ADD billing_status VARCHAR(32) NOT NULL DEFAULT \'active\'');
            $this->addSql('ALTER TABLE organization ADD subscription_current_period_end DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE organization ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('ALTER TABLE organization ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('UPDATE organization SET subscription_plan = \'unlimited\', billing_status = \'active\', trial_ends_at = NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
