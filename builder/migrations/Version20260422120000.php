<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plusieurs comptes clients peuvent partager le même libellé d’organisation (ex. « Edame ») :
 * l’identifiant métier reste l’id et le préfixe webhook, pas le nom affiché.
 */
final class Version20260422120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime l’unicité globale sur organization.name (noms d’organisation pouvant se dupliquer entre locataires).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORGANIZATION_NAME ON organization');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_NAME ON organization (name)');
    }
}
