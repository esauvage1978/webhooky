<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passage aux avatars Micah 64 (p01–p64) : conversion des clés Adventurer / Notionists.';
    }

    public function up(Schema $schema): void
    {
        $map = [
            'fox' => 'p01',
            'panda' => 'p02',
            'eagle' => 'p03',
            'whale' => 'p04',
            'leaf' => 'p05',
            'bolt' => 'p06',
            'moon' => 'p07',
            'ruby' => 'p08',
            'aurora' => 'p09',
            'boreal' => 'p10',
            'coral' => 'p11',
            'dune' => 'p12',
            'ember' => 'p13',
            'fjord' => 'p14',
            'glade' => 'p15',
            'harbor' => 'p16',
            'iris' => 'p17',
            'jasper' => 'p18',
            'kelp' => 'p19',
            'lotus' => 'p20',
            'mist' => 'p21',
            'nimbus' => 'p22',
            'orchid' => 'p23',
            'prism' => 'p24',
        ];
        foreach ($map as $from => $to) {
            $this->addSql(sprintf('UPDATE `user` SET avatar_key = \'%s\' WHERE avatar_key = \'%s\'', $to, $from));
        }
    }

    public function down(Schema $schema): void
    {
        $map = [
            'p01' => 'fox',
            'p02' => 'panda',
            'p03' => 'eagle',
            'p04' => 'whale',
            'p05' => 'leaf',
            'p06' => 'bolt',
            'p07' => 'moon',
            'p08' => 'ruby',
            'p09' => 'aurora',
            'p10' => 'boreal',
            'p11' => 'coral',
            'p12' => 'dune',
            'p13' => 'ember',
            'p14' => 'fjord',
            'p15' => 'glade',
            'p16' => 'harbor',
            'p17' => 'iris',
            'p18' => 'jasper',
            'p19' => 'kelp',
            'p20' => 'lotus',
            'p21' => 'mist',
            'p22' => 'nimbus',
            'p23' => 'orchid',
            'p24' => 'prism',
        ];
        foreach ($map as $from => $to) {
            $this->addSql(sprintf('UPDATE `user` SET avatar_key = \'%s\' WHERE avatar_key = \'%s\'', $to, $from));
        }
    }
}
