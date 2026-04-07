<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil utilisateur (nom d’affichage, avatar) et suivi d’onboarding (profil + plan gestionnaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD display_name VARCHAR(120) DEFAULT NULL, ADD avatar_key VARCHAR(32) DEFAULT NULL, ADD profile_completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD plan_onboarding_completed TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('UPDATE `user` SET display_name = SUBSTRING_INDEX(email, \'@\', 1), avatar_key = \'fox\', profile_completed_at = NOW(), plan_onboarding_completed = 1 WHERE profile_completed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP display_name, DROP avatar_key, DROP profile_completed_at, DROP plan_onboarding_completed');
    }
}
