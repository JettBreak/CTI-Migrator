<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts in the app database instead of .env hashes: users, their password history, the account
 * audit trail and the maker-checker requests between user administrators.
 */
final class Version20260928152550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user, password_history, user_audit_entry, user_change_request';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, must_change_password TINYINT DEFAULT 1 NOT NULL, password_changed_at DATETIME NOT NULL, failed_login_count INT DEFAULT 0 NOT NULL, locked_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, last_login_ip VARCHAR(45) DEFAULT NULL, previous_login_at DATETIME DEFAULT NULL, previous_login_ip VARCHAR(45) DEFAULT NULL, session_token VARCHAR(64) DEFAULT NULL, active_since DATETIME NOT NULL, created_at DATETIME NOT NULL, deactivated_at DATETIME DEFAULT NULL, deactivated_by VARCHAR(180) DEFAULT NULL, username VARCHAR(180) NOT NULL, display_name VARCHAR(120) NOT NULL, role VARCHAR(32) NOT NULL, created_by VARCHAR(180) NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE password_history (id INT AUTO_INCREMENT NOT NULL, hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_password_history_user (user_id, created_at), INDEX IDX_F352144A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_audit_entry (id INT AUTO_INCREMENT NOT NULL, actor VARCHAR(180) NOT NULL, action VARCHAR(40) NOT NULL, target VARCHAR(180) DEFAULT NULL, details LONGTEXT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_user_audit_target (target, created_at), INDEX idx_user_audit_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_change_request (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, reviewed_by VARCHAR(180) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, review_note LONGTEXT DEFAULT NULL, type VARCHAR(20) NOT NULL, target_username VARCHAR(180) NOT NULL, requested_by VARCHAR(180) NOT NULL, reason LONGTEXT NOT NULL, requested_at DATETIME NOT NULL, display_name VARCHAR(120) DEFAULT NULL, role VARCHAR(32) DEFAULT NULL, INDEX idx_user_change_status (status, requested_at), INDEX idx_user_change_target (target_username, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE password_history ADD CONSTRAINT FK_F352144A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_history DROP FOREIGN KEY FK_F352144A76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE password_history');
        $this->addSql('DROP TABLE user_audit_entry');
        $this->addSql('DROP TABLE user_change_request');
    }
}
