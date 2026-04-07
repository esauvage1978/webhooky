<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adhésions multi-organisations (organization_membership) + nom d’organisation unique. '
            .'Échec si des noms d’organisation sont en double (à corriger manuellement avant migration).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_membership (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, organization_id INT NOT NULL, UNIQUE INDEX UNIQ_MEMBERSHIP_USER_ORG (user_id, organization_id), INDEX IDX_org_membership_org (organization_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE organization_membership ADD CONSTRAINT FK_org_membership_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE organization_membership ADD CONSTRAINT FK_org_membership_organization FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO organization_membership (user_id, organization_id) SELECT id, organization_id FROM `user` WHERE organization_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_NAME ON organization (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORGANIZATION_NAME ON organization');
        $this->addSql('ALTER TABLE organization_membership DROP FOREIGN KEY FK_org_membership_user');
        $this->addSql('ALTER TABLE organization_membership DROP FOREIGN KEY FK_org_membership_organization');
        $this->addSql('DROP TABLE organization_membership');
    }
}
