<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table mailjet (organisation, clés API, auteur, date de création).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mailjet (id INT AUTO_INCREMENT NOT NULL, organization_id INT NOT NULL, created_by_id INT NOT NULL, name VARCHAR(180) NOT NULL, api_key_public VARCHAR(255) NOT NULL, api_key_private VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX IDX_zapier_mailjet_organization ON mailjet (organization_id)');
        $this->addSql('CREATE INDEX IDX_zapier_mailjet_created_by ON mailjet (created_by_id)');
        $this->addSql('ALTER TABLE mailjet ADD CONSTRAINT FK_zapier_mailjet_organization FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailjet ADD CONSTRAINT FK_zapier_mailjet_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailjet DROP FOREIGN KEY FK_zapier_mailjet_organization');
        $this->addSql('ALTER TABLE mailjet DROP FOREIGN KEY FK_zapier_mailjet_created_by');
        $this->addSql('DROP TABLE mailjet');
    }
}
