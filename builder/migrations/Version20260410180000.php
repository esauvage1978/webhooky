<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression de la colonne avatar_key (fonctionnalité avatar retirée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP avatar_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD avatar_key VARCHAR(32) DEFAULT NULL');
    }
}
