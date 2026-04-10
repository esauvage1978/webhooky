<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : dates de création et dernière connexion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE `user` ADD last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP last_login_at');
        $this->addSql('ALTER TABLE `user` DROP created_at');
    }
}
