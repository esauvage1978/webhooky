<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sépare trigger (form_webhook) et actions (form_webhook_action) + journaux par action (form_webhook_action_log).
 */
final class Version20260405140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhooks formulaires : actions multiples, logs par action.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE form_webhook_action (id INT AUTO_INCREMENT NOT NULL, form_webhook_id INT NOT NULL, mailjet_id INT NOT NULL, sort_order INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, mailjet_template_id INT NOT NULL, template_language TINYINT(1) DEFAULT 1 NOT NULL, variable_mapping JSON NOT NULL, recipient_email_post_key VARCHAR(128) DEFAULT NULL, recipient_name_post_key VARCHAR(128) DEFAULT NULL, default_recipient_email VARCHAR(255) DEFAULT NULL, INDEX IDX_form_webhook_action_webhook_sort (form_webhook_id, sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE form_webhook_action ADD CONSTRAINT FK_form_webhook_action_webhook FOREIGN KEY (form_webhook_id) REFERENCES form_webhook (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_webhook_action ADD CONSTRAINT FK_form_webhook_action_mailjet FOREIGN KEY (mailjet_id) REFERENCES mailjet (id) ON DELETE RESTRICT');

        $this->addSql('INSERT INTO form_webhook_action (form_webhook_id, sort_order, active, mailjet_id, mailjet_template_id, template_language, variable_mapping, recipient_email_post_key, recipient_name_post_key, default_recipient_email) SELECT id, 0, active, mailjet_id, mailjet_template_id, template_language, variable_mapping, recipient_email_post_key, recipient_name_post_key, default_recipient_email FROM form_webhook');

        $this->addSql('CREATE TABLE form_webhook_action_log (id INT AUTO_INCREMENT NOT NULL, form_webhook_log_id INT NOT NULL, form_webhook_action_id INT DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, variables_sent JSON DEFAULT NULL, to_email VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, mailjet_http_status INT DEFAULT NULL, mailjet_response_body LONGTEXT DEFAULT NULL, mailjet_message_id VARCHAR(64) DEFAULT NULL, error_detail LONGTEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, INDEX IDX_form_webhook_action_log_parent (form_webhook_log_id, sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE form_webhook_action_log ADD CONSTRAINT FK_form_webhook_action_log_log FOREIGN KEY (form_webhook_log_id) REFERENCES form_webhook_log (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_webhook_action_log ADD CONSTRAINT FK_form_webhook_action_log_action FOREIGN KEY (form_webhook_action_id) REFERENCES form_webhook_action (id) ON DELETE SET NULL');

        $this->addSql('INSERT INTO form_webhook_action_log (form_webhook_log_id, form_webhook_action_id, sort_order, variables_sent, to_email, status, mailjet_http_status, mailjet_response_body, mailjet_message_id, error_detail, duration_ms) SELECT l.id, a.id, 0, l.variables_sent, l.to_email, l.status, l.mailjet_http_status, l.mailjet_response_body, l.mailjet_message_id, l.error_detail, NULL FROM form_webhook_log l INNER JOIN form_webhook_action a ON a.form_webhook_id = l.form_webhook_id AND a.sort_order = 0');

        $this->addSql('ALTER TABLE form_webhook DROP FOREIGN KEY FK_form_webhook_mailjet');
        $this->addSql('DROP INDEX IDX_form_webhook_mailjet ON form_webhook');
        $this->addSql('ALTER TABLE form_webhook DROP mailjet_id, DROP mailjet_template_id, DROP template_language, DROP variable_mapping, DROP recipient_email_post_key, DROP recipient_name_post_key, DROP default_recipient_email');

        $this->addSql('ALTER TABLE form_webhook_log DROP variables_sent, DROP to_email, DROP mailjet_http_status, DROP mailjet_response_body, DROP mailjet_message_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
