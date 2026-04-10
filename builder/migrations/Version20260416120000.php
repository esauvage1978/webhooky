<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migre les lignes mailjet vers service_connection (type mailjet) et rattache les actions.
 */
final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mailjet : données table mailjet → service_connection + form_webhook_action.service_connection_id.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $this->write('Migration Mail→service_connection ignorée (MySQL/MariaDB requis).');

            return;
        }

        $schemaMgr = $this->connection->createSchemaManager();
        if (!$schemaMgr->tablesExist(['mailjet', 'service_connection', 'form_webhook_action'])) {
            $this->write('Tables mailjet / service_connection / form_webhook_action absentes — migration ignorée.');

            return;
        }

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mailjet');
        if ($count === 0) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, organization_id, created_by_id, name, api_key_public, api_key_private, created_at FROM mailjet ORDER BY id ASC',
        );

        foreach ($rows as $r) {
            $json = json_encode([
                'apiKeyPublic' => (string) $r['api_key_public'],
                'apiKeyPrivate' => (string) $r['api_key_private'],
            ], JSON_THROW_ON_ERROR);

            $this->connection->executeStatement(
                'INSERT INTO service_connection (organization_id, created_by_id, type, name, config, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (int) $r['organization_id'],
                    $r['created_by_id'] !== null ? (int) $r['created_by_id'] : null,
                    'mailjet',
                    (string) $r['name'],
                    $json,
                    (string) $r['created_at'],
                ],
            );

            $newId = (int) $this->connection->lastInsertId();

            $this->connection->executeStatement(
                'UPDATE form_webhook_action SET service_connection_id = ?, mailjet_id = NULL WHERE mailjet_id = ?',
                [$newId, (int) $r['id']],
            );
        }

        $this->addSql('DELETE FROM mailjet');
    }

    public function down(Schema $schema): void
    {
        $this->write('Down non implémenté : restaurez une sauvegarde pour revenir en arrière.');
    }
}
