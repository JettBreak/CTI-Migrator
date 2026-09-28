<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rollback of a replaced batch (maker-checker): who requested and approved it, counters, and per row
 * when its account got its old number back or why the rollback left it alone.
 */
final class Version20260928100857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'migration_batch rollback columns; migration_row.rolled_back_at, rollback_error and index';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('ALTER TABLE migration_batch ADD rollback_from VARCHAR(32) DEFAULT NULL, ADD rollback_requested_by VARCHAR(180) DEFAULT NULL, ADD rollback_requested_at DATETIME DEFAULT NULL, ADD rollback_reason LONGTEXT DEFAULT NULL, ADD rollback_reviewed_by VARCHAR(180) DEFAULT NULL, ADD rollback_reviewed_at DATETIME DEFAULT NULL, ADD rollback_review_note LONGTEXT DEFAULT NULL, ADD rolled_back_account_count INT DEFAULT 0 NOT NULL, ADD rollback_skipped_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE migration_row ADD rolled_back_at DATETIME DEFAULT NULL, ADD rollback_error VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_migration_row_rollback ON migration_row (batch_id, rolled_back_at, current_account)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE migration_batch DROP rollback_from, DROP rollback_requested_by, DROP rollback_requested_at, DROP rollback_reason, DROP rollback_reviewed_by, DROP rollback_reviewed_at, DROP rollback_review_note, DROP rolled_back_account_count, DROP rollback_skipped_count');
        $this->addSql('DROP INDEX idx_migration_row_rollback ON migration_row');
        $this->addSql('ALTER TABLE migration_row DROP rolled_back_at, DROP rollback_error');
    }
}
