<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version sur form_webhook + table resource_audit_log (traçabilité webhooks et connecteurs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE resource_audit_log (
                id INT AUTO_INCREMENT NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                resource_type VARCHAR(32) NOT NULL,
                action VARCHAR(16) NOT NULL,
                resource_id INT NOT NULL,
                details JSON DEFAULT NULL,
                client_ip VARCHAR(45) DEFAULT NULL,
                organization_id INT DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                INDEX IDX_resource_audit_resource (resource_type, resource_id, occurred_at),
                INDEX IDX_resource_audit_org_time (organization_id, occurred_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE resource_audit_log ADD CONSTRAINT FK_resource_audit_org FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE resource_audit_log ADD CONSTRAINT FK_resource_audit_actor FOREIGN KEY (actor_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resource_audit_log DROP FOREIGN KEY FK_resource_audit_org');
        $this->addSql('ALTER TABLE resource_audit_log DROP FOREIGN KEY FK_resource_audit_actor');
        $this->addSql('DROP TABLE resource_audit_log');
        $this->addSql('ALTER TABLE form_webhook DROP version');
    }
}
