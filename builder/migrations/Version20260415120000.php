<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connecteurs tiers (service_connection) et actions de webhook généralisées (Mailjet + 9 intégrations).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_connection (
            id INT AUTO_INCREMENT NOT NULL,
            organization_id INT NOT NULL,
            created_by_id INT DEFAULT NULL,
            type VARCHAR(32) NOT NULL,
            name VARCHAR(180) NOT NULL,
            config JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_service_connection_org (organization_id),
            INDEX IDX_service_connection_created_by (created_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE service_connection ADD CONSTRAINT FK_SC_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_connection ADD CONSTRAINT FK_SC_USER FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE form_webhook_action DROP FOREIGN KEY FK_form_webhook_action_mailjet');
        $this->addSql('ALTER TABLE form_webhook_action MODIFY mailjet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD action_type VARCHAR(32) NOT NULL DEFAULT \'mailjet\'');
        $this->addSql('ALTER TABLE form_webhook_action ADD service_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD payload_template LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD sms_to_post_key VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD sms_to_default VARCHAR(48) DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD CONSTRAINT FK_FWA_SERVICE_CONN FOREIGN KEY (service_connection_id) REFERENCES service_connection (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_fwa_service_conn ON form_webhook_action (service_connection_id)');
        $this->addSql('ALTER TABLE form_webhook_action ADD CONSTRAINT FK_form_webhook_action_mailjet FOREIGN KEY (mailjet_id) REFERENCES mailjet (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook_action DROP FOREIGN KEY FK_FWA_SERVICE_CONN');
        $this->addSql('DROP INDEX IDX_fwa_service_conn ON form_webhook_action');
        $this->addSql('ALTER TABLE form_webhook_action DROP action_type, DROP service_connection_id, DROP payload_template, DROP sms_to_post_key, DROP sms_to_default');
        $this->addSql('ALTER TABLE form_webhook_action DROP FOREIGN KEY FK_form_webhook_action_mailjet');
        $this->addSql('ALTER TABLE form_webhook_action MODIFY mailjet_id INT NOT NULL');
        $this->addSql('ALTER TABLE form_webhook_action ADD CONSTRAINT FK_form_webhook_action_mailjet FOREIGN KEY (mailjet_id) REFERENCES mailjet (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE service_connection DROP FOREIGN KEY FK_SC_ORG');
        $this->addSql('ALTER TABLE service_connection DROP FOREIGN KEY FK_SC_USER');
        $this->addSql('DROP TABLE service_connection');
    }
}
