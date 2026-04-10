<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplacer les clés d’avatar obsolètes (8 Notionists) par les ids du jeu Adventurer (16).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE `user` SET avatar_key = \'aurora\' WHERE avatar_key = \'fox\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'boreal\' WHERE avatar_key = \'panda\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'coral\' WHERE avatar_key = \'eagle\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'dune\' WHERE avatar_key = \'whale\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'ember\' WHERE avatar_key = \'leaf\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'fjord\' WHERE avatar_key = \'bolt\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'glade\' WHERE avatar_key = \'moon\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'harbor\' WHERE avatar_key = \'ruby\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE `user` SET avatar_key = \'fox\' WHERE avatar_key = \'aurora\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'panda\' WHERE avatar_key = \'boreal\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'eagle\' WHERE avatar_key = \'coral\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'whale\' WHERE avatar_key = \'dune\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'leaf\' WHERE avatar_key = \'ember\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'bolt\' WHERE avatar_key = \'fjord\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'moon\' WHERE avatar_key = \'glade\'');
        $this->addSql('UPDATE `user` SET avatar_key = \'ruby\' WHERE avatar_key = \'harbor\'');
    }
}
