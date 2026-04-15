<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEO multi-tenant : organization_integration, pipeline_config sur actions, ai_settings sur organization.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_integration (
            id INT AUTO_INCREMENT NOT NULL,
            organization_id INT NOT NULL,
            type VARCHAR(32) NOT NULL,
            access_token_cipher LONGTEXT NOT NULL,
            refresh_token_cipher LONGTEXT NOT NULL,
            expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            scope LONGTEXT DEFAULT NULL,
            site_url VARCHAR(2048) DEFAULT NULL,
            extra JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_ORG_INT_ORG_TYPE (organization_id, type),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE organization_integration ADD CONSTRAINT FK_ORG_INT_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE form_webhook_action ADD pipeline_config JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE organization ADD ai_settings JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP ai_settings');
        $this->addSql('ALTER TABLE form_webhook_action DROP pipeline_config');
        $this->addSql('ALTER TABLE organization_integration DROP FOREIGN KEY FK_ORG_INT_ORGANIZATION');
        $this->addSql('DROP TABLE organization_integration');
    }
}
