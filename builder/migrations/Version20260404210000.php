<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-utilisateurs par organisation, invitation mot de passe, blocage, audit.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('DROP INDEX UNIQ_zapier_user_organization');
            $this->addSql('CREATE INDEX IDX_zapier_user_organization ON "user" (organization_id)');
            $this->addSql('ALTER TABLE "user" ADD COLUMN account_enabled INTEGER DEFAULT 1 NOT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN invite_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN invite_expires_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_INVITE_TOKEN ON "user" (invite_token)');
            $this->addSql('CREATE TABLE user_account_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, occurred_at DATETIME NOT NULL --(DC2Type:datetime_immutable), action VARCHAR(64) NOT NULL, target_email VARCHAR(180) DEFAULT NULL, details CLOB DEFAULT NULL --(DC2Type:json), client_ip VARCHAR(45) DEFAULT NULL, actor_user_id INTEGER DEFAULT NULL, target_user_id INTEGER DEFAULT NULL, organization_id INTEGER DEFAULT NULL, CONSTRAINT FK_user_audit_actor FOREIGN KEY (actor_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_user_audit_target FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_user_audit_org FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_user_audit_org_occurred ON user_account_audit_log (organization_id, occurred_at)');
        } else {
            $this->addSql('DROP INDEX UNIQ_zapier_user_organization ON `user`');
            $this->addSql('CREATE INDEX IDX_zapier_user_organization ON `user` (organization_id)');
            $this->addSql('ALTER TABLE `user` ADD account_enabled TINYINT(1) NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE `user` ADD invite_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE `user` ADD invite_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_INVITE_TOKEN ON `user` (invite_token)');
            $this->addSql('CREATE TABLE user_account_audit_log (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', action VARCHAR(64) NOT NULL, target_email VARCHAR(180) DEFAULT NULL, details JSON DEFAULT NULL, client_ip VARCHAR(45) DEFAULT NULL, actor_user_id INT DEFAULT NULL, target_user_id INT DEFAULT NULL, organization_id INT DEFAULT NULL, INDEX IDX_user_audit_org_occurred (organization_id, occurred_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE user_account_audit_log ADD CONSTRAINT FK_user_audit_actor FOREIGN KEY (actor_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE user_account_audit_log ADD CONSTRAINT FK_user_audit_target FOREIGN KEY (target_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE user_account_audit_log ADD CONSTRAINT FK_user_audit_org FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('DROP TABLE user_account_audit_log');
            $this->addSql('DROP INDEX UNIQ_USER_INVITE_TOKEN');
            $this->addSql('DROP INDEX IDX_zapier_user_organization');
            $this->addSql('ALTER TABLE "user" DROP COLUMN account_enabled');
            $this->addSql('ALTER TABLE "user" DROP COLUMN invite_token');
            $this->addSql('ALTER TABLE "user" DROP COLUMN invite_expires_at');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_zapier_user_organization ON "user" (organization_id)');
        } else {
            $this->addSql('ALTER TABLE user_account_audit_log DROP FOREIGN KEY FK_user_audit_actor');
            $this->addSql('ALTER TABLE user_account_audit_log DROP FOREIGN KEY FK_user_audit_target');
            $this->addSql('ALTER TABLE user_account_audit_log DROP FOREIGN KEY FK_user_audit_org');
            $this->addSql('DROP TABLE user_account_audit_log');
            $this->addSql('DROP INDEX UNIQ_USER_INVITE_TOKEN ON `user`');
            $this->addSql('ALTER TABLE `user` DROP account_enabled');
            $this->addSql('ALTER TABLE `user` DROP invite_token');
            $this->addSql('ALTER TABLE `user` DROP invite_expires_at');
            $this->addSql('DROP INDEX IDX_zapier_user_organization ON `user`');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_zapier_user_organization ON `user` (organization_id)');
        }
    }
}
