<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compteurs mensuels d’événements quota par organisation (alignés sur organization.events_consumed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_monthly_event_usage (
            id INT AUTO_INCREMENT NOT NULL,
            organization_id INT NOT NULL,
            year SMALLINT NOT NULL,
            month SMALLINT NOT NULL,
            event_count INT NOT NULL DEFAULT 0,
            INDEX IDX_OM_ORG (organization_id),
            UNIQUE INDEX uniq_org_month_year (organization_id, year, month),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE organization_monthly_event_usage ADD CONSTRAINT FK_OM_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_monthly_event_usage DROP FOREIGN KEY FK_OM_ORG');
        $this->addSql('DROP TABLE organization_monthly_event_usage');
    }
}
