<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Monitoring plateforme : correlation/attempts, tables agrégats/alertes/coûts, Messenger Doctrine.
 */
final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monitoring: colonnes logs, tables monitoring_*, messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook_log ADD correlation_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_log ADD http_status_response INT DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_log ADD quota_units_consumed INT DEFAULT NULL');
        $this->addSql('ALTER TABLE form_webhook_log ADD attempt_count INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE INDEX IDX_form_webhook_log_correlation ON form_webhook_log (correlation_id)');
        $this->addSql('CREATE INDEX IDX_form_webhook_log_received_status ON form_webhook_log (received_at, status)');

        $this->addSql('ALTER TABLE form_webhook_action_log ADD attempt INT DEFAULT 1 NOT NULL');

        $this->addSql('CREATE TABLE messenger_messages (
            id BIGINT AUTO_INCREMENT NOT NULL,
            body LONGTEXT NOT NULL,
            headers LONGTEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');

        $this->addSql('CREATE TABLE monitoring_metric_agg (
            id INT AUTO_INCREMENT NOT NULL,
            period_type VARCHAR(16) NOT NULL,
            period_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            metric_key VARCHAR(96) NOT NULL,
            organization_id INT DEFAULT NULL,
            dimension_hash VARCHAR(64) NOT NULL,
            dimensions JSON DEFAULT NULL,
            value_sum DOUBLE PRECISION NOT NULL,
            value_count INT NOT NULL,
            value_max DOUBLE PRECISION DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_mon_metric_agg ON monitoring_metric_agg (period_type, period_start, metric_key, organization_id, dimension_hash)');
        $this->addSql('CREATE INDEX IDX_mon_metric_agg_lookup ON monitoring_metric_agg (period_type, period_start, metric_key)');

        $this->addSql('CREATE TABLE monitoring_setting (
            id INT AUTO_INCREMENT NOT NULL,
            setting_key VARCHAR(96) NOT NULL,
            setting_value JSON NOT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_mon_setting_key ON monitoring_setting (setting_key)');

        $this->addSql('CREATE TABLE monitoring_alert (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(64) NOT NULL,
            domain VARCHAR(64) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message LONGTEXT NOT NULL,
            organization_id INT DEFAULT NULL,
            fingerprint VARCHAR(128) NOT NULL,
            status VARCHAR(16) NOT NULL,
            first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            acknowledged_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            occurrence_count INT NOT NULL,
            context JSON DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_mon_alert_fp ON monitoring_alert (fingerprint)');
        $this->addSql('CREATE INDEX IDX_mon_alert_status ON monitoring_alert (status, severity, last_seen_at)');

        $this->addSql('CREATE TABLE monitoring_incident (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            organization_id INT DEFAULT NULL,
            opened_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            summary LONGTEXT DEFAULT NULL,
            alert_ids JSON DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX IDX_mon_incident_status ON monitoring_incident (status, opened_at)');

        $this->addSql('CREATE TABLE pricing_rule (
            id INT AUTO_INCREMENT NOT NULL,
            provider VARCHAR(64) NOT NULL,
            channel VARCHAR(32) NOT NULL,
            unit VARCHAR(32) NOT NULL,
            unit_cost_cents INT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            label VARCHAR(255) NOT NULL,
            active TINYINT(1) NOT NULL,
            valid_from DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            valid_to DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX IDX_pricing_rule_active ON pricing_rule (active, channel, provider)');

        $this->addSql('CREATE TABLE monitoring_cost_entry (
            id INT AUTO_INCREMENT NOT NULL,
            period_day DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            organization_id INT DEFAULT NULL,
            channel VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            units DOUBLE PRECISION NOT NULL,
            cost_cents INT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            pricing_rule_id INT DEFAULT NULL,
            meta JSON DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_mon_cost_day ON monitoring_cost_entry (period_day, organization_id, channel, provider)');
        $this->addSql('CREATE INDEX IDX_mon_cost_day ON monitoring_cost_entry (period_day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE monitoring_cost_entry');
        $this->addSql('DROP TABLE pricing_rule');
        $this->addSql('DROP TABLE monitoring_incident');
        $this->addSql('DROP TABLE monitoring_alert');
        $this->addSql('DROP TABLE monitoring_setting');
        $this->addSql('DROP TABLE monitoring_metric_agg');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP INDEX IDX_form_webhook_log_received_status ON form_webhook_log');
        $this->addSql('DROP INDEX IDX_form_webhook_log_correlation ON form_webhook_log');
        $this->addSql('ALTER TABLE form_webhook_log DROP correlation_id, DROP http_status_response, DROP quota_units_consumed, DROP attempt_count');
        $this->addSql('ALTER TABLE form_webhook_action_log DROP attempt');
    }
}
