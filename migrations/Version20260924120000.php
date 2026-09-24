<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * App database (data_migration): migration batches, their rows, and the audit trail.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create migration_batch, migration_row and audit_entry';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('CREATE TABLE migration_batch (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(32) NOT NULL, submitted_at DATETIME DEFAULT NULL, reviewed_by VARCHAR(180) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, review_note LONGTEXT DEFAULT NULL, processed_at DATETIME DEFAULT NULL, failure_reason LONGTEXT DEFAULT NULL, uploaded_at DATETIME NOT NULL, filename VARCHAR(255) NOT NULL, uploaded_by VARCHAR(180) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE migration_row (id INT AUTO_INCREMENT NOT NULL, errors JSON NOT NULL, card_display VARCHAR(32) DEFAULT NULL, cardholder VARCHAR(255) DEFAULT NULL, applied_at DATETIME DEFAULT NULL, line_number INT NOT NULL, card_ref VARCHAR(30) NOT NULL, current_account VARCHAR(30) NOT NULL, new_account VARCHAR(30) NOT NULL, batch_id INT NOT NULL, INDEX IDX_32903B00F39EBE7A (batch_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_entry (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, actor VARCHAR(180) NOT NULL, action VARCHAR(40) NOT NULL, details LONGTEXT DEFAULT NULL, batch_id INT NOT NULL, INDEX IDX_2C6AF987F39EBE7A (batch_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE migration_row ADD CONSTRAINT FK_32903B00F39EBE7A FOREIGN KEY (batch_id) REFERENCES migration_batch (id)');
        $this->addSql('ALTER TABLE audit_entry ADD CONSTRAINT FK_2C6AF987F39EBE7A FOREIGN KEY (batch_id) REFERENCES migration_batch (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_entry DROP FOREIGN KEY FK_2C6AF987F39EBE7A');
        $this->addSql('ALTER TABLE migration_row DROP FOREIGN KEY FK_32903B00F39EBE7A');
        $this->addSql('DROP TABLE audit_entry');
        $this->addSql('DROP TABLE migration_row');
        $this->addSql('DROP TABLE migration_batch');
    }
}
