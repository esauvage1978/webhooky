<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compteurs événements (organization), nouvelle grille free/starter/pro + mapping des anciens forfaits.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE organization ADD COLUMN events_consumed INTEGER DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE organization ADD COLUMN events_extra_quota INTEGER DEFAULT 0 NOT NULL');
        } else {
            $this->addSql('ALTER TABLE organization ADD events_consumed INT NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE organization ADD events_extra_quota INT NOT NULL DEFAULT 0');
        }

        $this->addSql("UPDATE organization SET subscription_plan = 'free' WHERE subscription_plan IN ('trial')");
        $this->addSql("UPDATE organization SET subscription_plan = 'starter' WHERE subscription_plan IN ('single_webhook')");
        $this->addSql("UPDATE organization SET subscription_plan = 'pro' WHERE subscription_plan IN ('unlimited')");

        $this->addSql("UPDATE organization SET subscription_plan = 'pro' WHERE subscription_plan NOT IN ('free', 'starter', 'pro')");

        $this->addSql('UPDATE organization SET events_extra_quota = 0 WHERE subscription_plan = \'free\'');
        $this->addSql('UPDATE organization SET billing_status = \'active\' WHERE subscription_plan = \'free\' AND billing_status = \'trialing\'');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
