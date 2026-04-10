<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organisation : adresse de facturation + table organization_invoice (PDF).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD billing_line1 VARCHAR(255) DEFAULT NULL, ADD billing_line2 VARCHAR(255) DEFAULT NULL, ADD billing_postal_code VARCHAR(32) DEFAULT NULL, ADD billing_city VARCHAR(128) DEFAULT NULL, ADD billing_country VARCHAR(2) DEFAULT NULL');
        $this->addSql('CREATE TABLE organization_invoice (id INT AUTO_INCREMENT NOT NULL, organization_id INT NOT NULL, reference VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, amount_eur NUMERIC(10, 2) NOT NULL, issued_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', pdf_url VARCHAR(2048) DEFAULT NULL, INDEX IDX_organization_invoice_org_issued (organization_id, issued_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE organization_invoice ADD CONSTRAINT FK_organization_invoice_org FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_invoice DROP FOREIGN KEY FK_organization_invoice_org');
        $this->addSql('DROP TABLE organization_invoice');
        $this->addSql('ALTER TABLE organization DROP billing_line1, DROP billing_line2, DROP billing_postal_code, DROP billing_city, DROP billing_country');
    }
}
