<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Préfixe unique webhook par organisation (jeton ingress = préfixe + jeton workflow).';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $conn->executeStatement('ALTER TABLE organization ADD webhook_public_prefix VARCHAR(12) DEFAULT NULL');
        $conn->executeStatement('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_WEBHOOK_PUBLIC_PREFIX ON organization (webhook_public_prefix)');

        $rows = $conn->fetchAllAssociative('SELECT id FROM organization');
        $used = $conn->fetchFirstColumn('SELECT webhook_public_prefix FROM organization WHERE webhook_public_prefix IS NOT NULL');
        $usedSet = array_fill_keys(array_map('strval', $used), true);

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            do {
                $prefix = bin2hex(random_bytes(6));
            } while (isset($usedSet[$prefix]));
            $usedSet[$prefix] = true;
            $conn->executeStatement(
                'UPDATE organization SET webhook_public_prefix = ? WHERE id = ?',
                [$prefix, $id],
            );
        }

        $conn->executeStatement('ALTER TABLE organization CHANGE webhook_public_prefix webhook_public_prefix VARCHAR(12) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORGANIZATION_WEBHOOK_PUBLIC_PREFIX ON organization');
        $this->addSql('ALTER TABLE organization DROP webhook_public_prefix');
    }
}
