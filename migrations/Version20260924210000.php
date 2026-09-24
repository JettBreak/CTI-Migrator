<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Batches of any size: a batch keeps its row/account counts instead of loading every row, rows get
 * a "valid" flag for counting and listing invalid rows, and indexes for chunked validation and apply.
 * Existing batches are backfilled from their rows.
 */
final class Version20260924210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'migration_batch counters; migration_row.valid and chunking indexes';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('ALTER TABLE migration_batch ADD validated_row_count INT DEFAULT 0 NOT NULL, ADD invalid_row_count INT DEFAULT 0 NOT NULL, ADD account_count INT DEFAULT 0 NOT NULL, ADD applied_account_count INT DEFAULT 0 NOT NULL, ADD applied_link_count INT DEFAULT 0 NOT NULL, ADD row_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE migration_row ADD valid TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE migration_row SET valid = (JSON_LENGTH(errors) = 0)');
        // Links renamed by earlier batches were only recorded in their audit trail; left at 0.
        $this->addSql(<<<'SQL'
            UPDATE migration_batch b
            JOIN (
                SELECT batch_id, COUNT(*) AS n, SUM(valid = 0) AS invalid, COUNT(DISTINCT current_account) AS accounts,
                       COUNT(DISTINCT CASE WHEN applied_at IS NOT NULL THEN current_account END) AS applied
                FROM migration_row GROUP BY batch_id
            ) s ON s.batch_id = b.id
            SET b.row_count = s.n, b.validated_row_count = s.n, b.invalid_row_count = s.invalid,
                b.account_count = s.accounts, b.applied_account_count = s.applied
            SQL);
        $this->addSql('CREATE INDEX idx_migration_row_current ON migration_row (batch_id, current_account)');
        $this->addSql('CREATE INDEX idx_migration_row_new ON migration_row (batch_id, new_account)');
        $this->addSql('CREATE INDEX idx_migration_row_pending ON migration_row (batch_id, applied_at, current_account)');
        $this->addSql('CREATE INDEX idx_migration_row_valid ON migration_row (batch_id, valid, line_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_migration_row_line ON migration_row (batch_id, line_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_migration_row_current ON migration_row');
        $this->addSql('DROP INDEX idx_migration_row_new ON migration_row');
        $this->addSql('DROP INDEX idx_migration_row_pending ON migration_row');
        $this->addSql('DROP INDEX idx_migration_row_valid ON migration_row');
        $this->addSql('DROP INDEX uniq_migration_row_line ON migration_row');
        $this->addSql('ALTER TABLE migration_row DROP valid');
        $this->addSql('ALTER TABLE migration_batch DROP validated_row_count, DROP invalid_row_count, DROP account_count, DROP applied_account_count, DROP applied_link_count, DROP row_count');
    }
}
