<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table organization et lien 1:1 vers user (MySQL / MariaDB).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `user` ADD organization_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_webhooky_user_organization FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_webhooky_user_organization ON `user` (organization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_webhooky_user_organization');
        $this->addSql('DROP INDEX UNIQ_webhooky_user_organization ON `user`');
        $this->addSql('ALTER TABLE `user` DROP organization_id');
        $this->addSql('DROP TABLE organization');
    }
}
