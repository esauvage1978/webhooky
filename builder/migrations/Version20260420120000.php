<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workflow : état brouillon (draft) / production (lifecycle).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE form_webhook ADD lifecycle VARCHAR(16) NOT NULL DEFAULT 'production'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook DROP lifecycle');
    }
}
