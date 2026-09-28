<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Batches can be submitted with their rows to correct skipped; when such a batch completes, the migrated
 * rows and the rows to correct are exported as export jobs of their own kind, linked to the batch.
 */
final class Version20260928062550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'migration_batch.skips_invalid_rows; export_job.kind and export_job.batch_id';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('ALTER TABLE export_job ADD kind VARCHAR(32) DEFAULT \'cards\' NOT NULL, ADD batch_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE export_job ADD CONSTRAINT FK_93DEA7C8F39EBE7A FOREIGN KEY (batch_id) REFERENCES migration_batch (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_93DEA7C8F39EBE7A ON export_job (batch_id)');
        $this->addSql('ALTER TABLE migration_batch ADD skips_invalid_rows TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_job DROP FOREIGN KEY FK_93DEA7C8F39EBE7A');
        $this->addSql('DROP INDEX IDX_93DEA7C8F39EBE7A ON export_job');
        $this->addSql('ALTER TABLE export_job DROP kind, DROP batch_id');
        $this->addSql('ALTER TABLE migration_batch DROP skips_invalid_rows');
    }
}
