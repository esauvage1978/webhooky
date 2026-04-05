<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhooks formulaires (FormWebhook) + journal (FormWebhookLog).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE form_webhook (id INT AUTO_INCREMENT NOT NULL, organization_id INT NOT NULL, mailjet_id INT NOT NULL, public_token VARCHAR(36) NOT NULL, name VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, mailjet_template_id INT NOT NULL, template_language TINYINT(1) DEFAULT 1 NOT NULL, variable_mapping JSON NOT NULL, recipient_email_post_key VARCHAR(128) DEFAULT NULL, recipient_name_post_key VARCHAR(128) DEFAULT NULL, default_recipient_email VARCHAR(255) DEFAULT NULL, metadata JSON DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_form_webhook_public_token (public_token), INDEX IDX_form_webhook_organization (organization_id), INDEX IDX_form_webhook_mailjet (mailjet_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE form_webhook_log (id INT AUTO_INCREMENT NOT NULL, form_webhook_id INT NOT NULL, received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', client_ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, content_type VARCHAR(255) DEFAULT NULL, raw_body LONGTEXT DEFAULT NULL, parsed_input JSON DEFAULT NULL, variables_sent JSON DEFAULT NULL, to_email VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, mailjet_http_status INT DEFAULT NULL, mailjet_response_body LONGTEXT DEFAULT NULL, mailjet_message_id VARCHAR(64) DEFAULT NULL, error_detail LONGTEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, INDEX IDX_form_webhook_log_webhook_received (form_webhook_id, received_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE form_webhook ADD CONSTRAINT FK_form_webhook_organization FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_webhook ADD CONSTRAINT FK_form_webhook_mailjet FOREIGN KEY (mailjet_id) REFERENCES mailjet (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE form_webhook_log ADD CONSTRAINT FK_form_webhook_log_webhook FOREIGN KEY (form_webhook_id) REFERENCES form_webhook (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook_log DROP FOREIGN KEY FK_form_webhook_log_webhook');
        $this->addSql('ALTER TABLE form_webhook DROP FOREIGN KEY FK_form_webhook_organization');
        $this->addSql('ALTER TABLE form_webhook DROP FOREIGN KEY FK_form_webhook_mailjet');
        $this->addSql('DROP TABLE form_webhook_log');
        $this->addSql('DROP TABLE form_webhook');
    }
}
