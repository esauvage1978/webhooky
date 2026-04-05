<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FormWebhook : créateur, notifications (e-mail, erreur/succès).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook ADD created_by_id INT DEFAULT NULL, ADD notification_email_source VARCHAR(16) NOT NULL DEFAULT \'creator\', ADD notification_custom_email VARCHAR(255) DEFAULT NULL, ADD notify_on_error TINYINT(1) NOT NULL DEFAULT 1, ADD notify_on_success TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE form_webhook ADD CONSTRAINT FK_form_webhook_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_form_webhook_created_by ON form_webhook (created_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook DROP FOREIGN KEY FK_form_webhook_created_by');
        $this->addSql('DROP INDEX IDX_form_webhook_created_by ON form_webhook');
        $this->addSql('ALTER TABLE form_webhook DROP created_by_id, DROP notification_email_source, DROP notification_custom_email, DROP notify_on_error, DROP notify_on_success');
    }
}
