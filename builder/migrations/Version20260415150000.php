<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GSC multi-tenant au niveau projet : 1 intégration par projet.
 */
final class Version20260415150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattache organization_integration à webhook_project (project_id) au lieu de organization_id.';
    }

    public function up(Schema $schema): void
    {
        // 1) Ajout project_id nullable (backfill), puis contrainte.
        $this->addSql('ALTER TABLE organization_integration ADD project_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_integration ADD CONSTRAINT FK_ORG_INT_PROJECT FOREIGN KEY (project_id) REFERENCES webhook_project (id) ON DELETE CASCADE');

        // Backfill : affecte le projet par défaut de l’organisation.
        // Note : si plusieurs projets par défaut (anomalie), on prend l’ID minimal.
        $this->addSql(<<<SQL
UPDATE organization_integration oi
JOIN (
  SELECT p.organization_id, MIN(p.id) AS pid
  FROM webhook_project p
  WHERE p.is_default = 1 OR p.name = 'Général'
  GROUP BY p.organization_id
) x ON x.organization_id = oi.organization_id
SET oi.project_id = x.pid
WHERE oi.project_id IS NULL
SQL);

        // Si aucun projet n’existe encore (base neuve), aucune ligne à backfill → OK.
        $this->addSql('ALTER TABLE organization_integration MODIFY project_id INT NOT NULL');

        // 2) On enlève l’ancien lien organisation (désormais dérivé via webhook_project.organization_id).
        $this->addSql('ALTER TABLE organization_integration DROP FOREIGN KEY FK_ORG_INT_ORGANIZATION');
        $this->addSql('DROP INDEX IDX_ORG_INT_ORG_TYPE ON organization_integration');
        $this->addSql('ALTER TABLE organization_integration DROP organization_id');

        // 3) Index : 1 intégration GSC par projet (unicité).
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORG_INT_PROJECT_TYPE ON organization_integration (project_id, type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORG_INT_PROJECT_TYPE ON organization_integration');

        $this->addSql('ALTER TABLE organization_integration ADD organization_id INT NOT NULL');
        $this->addSql('ALTER TABLE organization_integration ADD CONSTRAINT FK_ORG_INT_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_ORG_INT_ORG_TYPE ON organization_integration (organization_id, type)');

        // Backfill org_id depuis project.
        $this->addSql(<<<SQL
UPDATE organization_integration oi
JOIN webhook_project p ON p.id = oi.project_id
SET oi.organization_id = p.organization_id
WHERE oi.organization_id IS NULL
SQL);

        $this->addSql('ALTER TABLE organization_integration DROP FOREIGN KEY FK_ORG_INT_PROJECT');
        $this->addSql('ALTER TABLE organization_integration DROP project_id');
    }
}

