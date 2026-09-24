<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Background card exports: the export_job table and Messenger's queue table (async transport).
 */
final class Version20260924170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create export_job and messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('CREATE TABLE export_job (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(16) NOT NULL, rows_written INT NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, error LONGTEXT DEFAULT NULL, requested_by VARCHAR(180) NOT NULL, status_filter VARCHAR(100) DEFAULT NULL, expected_rows INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE export_job');
    }
}
