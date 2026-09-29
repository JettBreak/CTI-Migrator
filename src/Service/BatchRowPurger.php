<?php

namespace App\Service;

use App\Entity\AuditEntry;
use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use App\Repository\MigrationBatchRepository;
use App\Repository\MigrationRowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * DATA RETENTION: PERMANENT DELETION OF BATCH ROWS. A FIXED RULE, NOT A SETTING.
 *
 * 90 days after a batch ended Rejected, Invalid or Failed, every row of its mapping file stored in
 * migration_row is deleted permanently. None of these batches changed core: a rejected batch was
 * never applied, an invalid one never submitted as it stood, and a failed one renamed nothing
 * (a batch that renamed some accounts before stopping is Halted, not Failed). Their rows only
 * describe a file that was never used, and they would otherwise pile up for ever.
 *
 * - When: during the regular cleanup (hourly in the background worker, "Run cleanup now" on the
 *   Background worker page, and php bin/console app:exports:cleanup). The 90 days count from when the
 *   batch ended: failed or processed (processedAt), rejected (reviewedAt), or, for an Invalid batch,
 *   uploaded (validation finishes moments after the upload).
 * - What is kept: the batch record with its file name, status, counts and dates, and its audit
 *   trail, which records the deletion ("rows_purged", with how many rows went).
 * - What is never touched: batches in any other status. Completed, Halted and Rolled back batches keep
 *   their rows for good, because rollback, the migrated-accounts report and the audit of what changed
 *   in core depend on them; so do batches still in progress.
 * - After the deletion: the batch page says the rows are gone, and an Invalid batch can no longer
 *   proceed with its valid rows (BatchWorkflow::submitValidRows()); the file must be uploaded again.
 *
 * THE DELETION CANNOT BE UNDONE. The app keeps no copy of the rows, and the uploaded mapping file
 * itself is removed once validation has finished. Restoring purged rows is only possible from a
 * database backup taken before the deletion; the app has no way to do it.
 *
 * The retention period and the statuses are constants on purpose: changing them is a code change,
 * reviewed like any other, not a configuration switch.
 */
final class BatchRowPurger
{
    public const RETENTION = '90 days';
    /** @var list<BatchStatus> */
    public const STATUSES = [BatchStatus::Rejected, BatchStatus::Invalid, BatchStatus::Failed];

    public function __construct(
        private readonly MigrationBatchRepository $batches,
        private readonly MigrationRowRepository $rows,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Deletes the rows of every batch past its retention; $by is who ran the cleanup, for the audit trail.
     *
     * @return array{batches: int, rows: int}
     */
    public function purge(string $by): array
    {
        $report = ['batches' => 0, 'rows' => 0];
        foreach ($this->batches->findWithRowsToPurge(self::STATUSES, new \DateTimeImmutable('-'.self::RETENTION)) as $batch) {
            $removed = $this->rows->purgeForBatch($batch);
            $this->em->persist(new AuditEntry($batch, $by, 'rows_purged', \sprintf(
                '%s row(s) permanently deleted under the fixed data-retention rule: %s after the batch ended %s. They cannot be restored; the batch record, its counts and this audit trail are kept.',
                number_format($removed), self::RETENTION, $batch->getStatus()->label(),
            )));
            $this->em->flush();
            ++$report['batches'];
            $report['rows'] += $removed;
            $this->logger->notice('Data retention: permanently deleted {rows} row(s) of batch #{batch} ({status}), run by {by}', ['rows' => $removed, 'batch' => $batch->getId(), 'status' => $batch->getStatus()->value, 'by' => $by]);
        }

        return $report;
    }

    /** Whether the retention rule applies to $batch's status at all. */
    public static function applies(MigrationBatch $batch): bool
    {
        return \in_array($batch->getStatus(), self::STATUSES, true);
    }

    /** When the rows of $batch are (or were) due for deletion; null when the rule does not apply to it. */
    public static function dueOn(MigrationBatch $batch): ?\DateTimeImmutable
    {
        if (!self::applies($batch)) {
            return null;
        }

        return ($batch->getProcessedAt() ?? $batch->getReviewedAt() ?? $batch->getUploadedAt())->modify('+'.self::RETENTION);
    }
}
