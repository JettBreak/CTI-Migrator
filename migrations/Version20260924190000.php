<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Export progress is measured in cards (what the estimate counts), not CSV rows: a card linked
 * to several accounts produces several rows, which pushed progress past the expected total.
 */
final class Version20260924190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'export_job: rename expected_rows to expected_cards, add cards_written';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('ALTER TABLE export_job ADD cards_written INT DEFAULT 0 NOT NULL, CHANGE expected_rows expected_cards INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_job DROP cards_written, CHANGE expected_cards expected_rows INT NOT NULL');
    }
}
