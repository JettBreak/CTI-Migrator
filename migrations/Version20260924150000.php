<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Batches rename accounts: record how many cards each rename affects, and widen the
 * account columns so over-long input is stored and reported instead of rejected by MySQL.
 */
final class Version20260924150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add migration_row.linked_cards; widen account columns to 64';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('ALTER TABLE migration_row ADD linked_cards INT DEFAULT NULL, CHANGE current_account current_account VARCHAR(64) NOT NULL, CHANGE new_account new_account VARCHAR(64) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE migration_row DROP linked_cards, CHANGE current_account current_account VARCHAR(30) NOT NULL, CHANGE new_account new_account VARCHAR(30) NOT NULL');
    }
}
