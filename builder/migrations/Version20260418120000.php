<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commentaire optionnel sur form_webhook_action';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook_action ADD action_comment LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_webhook_action DROP action_comment');
    }
}
