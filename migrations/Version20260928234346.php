<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dates move from UTC to the app's timezone (APP_TIMEZONE, e.g. Asia/Manila): every table with
 * dates gets a "timezone" column (e.g. UTC+08:00), and the dates already stored, which were UTC,
 * are shifted to that timezone so old and new rows read the same way. migration_row gets no column
 * of its own: its dates are in the timezone of its batch.
 */
final class Version20260928234346 extends AbstractMigration
{
    /** Tables with a timezone column, and their date columns. */
    private const DATES = [
        'app_user' => ['password_changed_at', 'locked_at', 'last_login_at', 'previous_login_at', 'active_since', 'created_at', 'deactivated_at'],
        'audit_entry' => ['created_at'],
        'export_job' => ['created_at', 'finished_at'],
        'migration_batch' => ['submitted_at', 'reviewed_at', 'processed_at', 'rollback_requested_at', 'rollback_reviewed_at', 'uploaded_at'],
        'password_history' => ['created_at'],
        'user_audit_entry' => ['created_at'],
        'user_change_request' => ['reviewed_at', 'requested_at'],
    ];
    /** migration_row's dates, in its batch's timezone. */
    private const ROW_DATES = ['applied_at', 'rolled_back_at'];

    public function getDescription(): string
    {
        return 'timezone column on every table with dates; existing UTC dates shifted to APP_TIMEZONE';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('prmaster'), 'APP_DATABASE_URL points at the core database; it must point at the app database (e.g. data_migration).');

        // The kernel has set PHP's timezone from APP_TIMEZONE.
        $now = new \DateTimeImmutable();
        $offset = $now->getOffset();
        $label = 'UTC'.$now->format('P');

        foreach (self::DATES as $table => $columns) {
            $this->addSql(sprintf("ALTER TABLE %s ADD timezone VARCHAR(9) DEFAULT '%s' NOT NULL", $table, $label));
            if (0 !== $offset) {
                $this->addSql(sprintf('UPDATE %s SET %s', $table, implode(', ', array_map(
                    static fn (string $column) => sprintf('%1$s = DATE_ADD(%1$s, INTERVAL %2$d SECOND)', $column, $offset),
                    $columns,
                ))));
            }
            // New rows always say which offset they use (App\Doctrine\TimezoneRecorder); no default.
            $this->addSql(sprintf('ALTER TABLE %s ALTER timezone DROP DEFAULT', $table));
        }
        if (0 !== $offset) {
            $this->addSql(sprintf('UPDATE migration_row SET %s', implode(', ', array_map(
                static fn (string $column) => sprintf('%1$s = DATE_ADD(%1$s, INTERVAL %2$d SECOND)', $column, $offset),
                self::ROW_DATES,
            ))));
        }
    }

    public function down(Schema $schema): void
    {
        // Back to UTC, using each row's own offset; migration_row first, while its batches still have theirs.
        $this->addSql(sprintf('UPDATE migration_row r JOIN migration_batch b ON b.id = r.batch_id SET %s', implode(', ', array_map(
            static fn (string $column) => sprintf('r.%1$s = DATE_SUB(r.%1$s, INTERVAL %2$s SECOND)', $column, self::offsetSeconds('b.timezone')),
            self::ROW_DATES,
        ))));
        foreach (self::DATES as $table => $columns) {
            $this->addSql(sprintf('UPDATE %s SET %s', $table, implode(', ', array_map(
                static fn (string $column) => sprintf('%1$s = DATE_SUB(%1$s, INTERVAL %2$s SECOND)', $column, self::offsetSeconds('timezone')),
                $columns,
            ))));
            $this->addSql(sprintf('ALTER TABLE %s DROP timezone', $table));
        }
    }

    /** SQL for the seconds in an offset column such as "UTC+08:00" (or "UTC-03:30"). */
    private static function offsetSeconds(string $column): string
    {
        return sprintf("(CAST(SUBSTRING(%1\$s, 4, 3) AS SIGNED) * 3600 + IF(SUBSTRING(%1\$s, 4, 1) = '-', -1, 1) * CAST(SUBSTRING(%1\$s, 8, 2) AS SIGNED) * 60)", $column);
    }
}
