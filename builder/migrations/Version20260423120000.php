<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rétablit l’unicité globale du nom d’organisation (annule Version20260422120000 côté schéma).
 * Échoue s’il existe des doublons de nom : les fusionner ou renommer avant migration.
 */
final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recrée UNIQ_ORGANIZATION_NAME sur organization(name).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_NAME ON organization (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORGANIZATION_NAME ON organization');
    }
}
