<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table app_option — paramètres clé/valeur (admin)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_option (
            id INT AUTO_INCREMENT NOT NULL,
            option_value LONGTEXT NOT NULL,
            option_name VARCHAR(191) NOT NULL,
            domain VARCHAR(191) DEFAULT NULL,
            category VARCHAR(191) NOT NULL,
            comment LONGTEXT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_option');
    }
}
