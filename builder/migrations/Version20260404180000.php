<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateur : vérification e-mail et réinitialisation mot de passe.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE "user" ADD COLUMN email_verified INTEGER DEFAULT 1 NOT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN email_verification_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN email_verification_expires_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)');
            $this->addSql('ALTER TABLE "user" ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN password_reset_expires_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL_VERIFICATION_TOKEN ON "user" (email_verification_token)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_PASSWORD_RESET_TOKEN ON "user" (password_reset_token)');
        } else {
            $this->addSql('ALTER TABLE `user` ADD email_verified TINYINT(1) NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE `user` ADD email_verification_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE `user` ADD email_verification_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE `user` ADD password_reset_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE `user` ADD password_reset_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL_VERIFICATION_TOKEN ON `user` (email_verification_token)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_PASSWORD_RESET_TOKEN ON `user` (password_reset_token)');
        }
    }

    public function down(Schema $schema): void
    {
        $sqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        if ($sqlite) {
            $this->addSql('DROP INDEX UNIQ_USER_EMAIL_VERIFICATION_TOKEN');
            $this->addSql('DROP INDEX UNIQ_USER_PASSWORD_RESET_TOKEN');
            $this->addSql('ALTER TABLE "user" DROP COLUMN email_verified');
            $this->addSql('ALTER TABLE "user" DROP COLUMN email_verification_token');
            $this->addSql('ALTER TABLE "user" DROP COLUMN email_verification_expires_at');
            $this->addSql('ALTER TABLE "user" DROP COLUMN password_reset_token');
            $this->addSql('ALTER TABLE "user" DROP COLUMN password_reset_expires_at');
        } else {
            $this->addSql('DROP INDEX UNIQ_USER_EMAIL_VERIFICATION_TOKEN ON `user`');
            $this->addSql('DROP INDEX UNIQ_USER_PASSWORD_RESET_TOKEN ON `user`');
            $this->addSql('ALTER TABLE `user` DROP email_verified');
            $this->addSql('ALTER TABLE `user` DROP email_verification_token');
            $this->addSql('ALTER TABLE `user` DROP email_verification_expires_at');
            $this->addSql('ALTER TABLE `user` DROP password_reset_token');
            $this->addSql('ALTER TABLE `user` DROP password_reset_expires_at');
        }
    }
}
