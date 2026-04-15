<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth Google Search Console : identifiants client par projet (webhook_project).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_project ADD google_oauth_client_id VARCHAR(191) DEFAULT \'\' NOT NULL');
        $this->addSql('ALTER TABLE webhook_project ADD google_oauth_client_secret_cipher LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_project DROP google_oauth_client_id');
        $this->addSql('ALTER TABLE webhook_project DROP google_oauth_client_secret_cipher');
    }
}
