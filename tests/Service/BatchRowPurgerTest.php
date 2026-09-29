<?php

namespace App\Tests\Service;

use App\Entity\AuditEntry;
use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Enum\BatchStatus;
use App\Migration\BatchLocked;
use App\Migration\BatchWorkflow;
use App\Repository\MigrationRowRepository;
use App\Service\BatchRowPurger;
use App\Service\ExportCleaner;
use App\Tests\AppTestCase;

/** The fixed data-retention rule: rows of Rejected, Invalid and Failed batches go 90 days after the batch ended. */
final class BatchRowPurgerTest extends AppTestCase
{
    public function testRowsOfBatchesThatNeverChangedCoreAreDeletedAfterNinetyDays(): void
    {
        $rejected = $this->batch(BatchStatus::Rejected, endedDaysAgo: 91);
        $invalid = $this->batch(BatchStatus::Invalid, endedDaysAgo: 120);
        $failed = $this->batch(BatchStatus::Failed, endedDaysAgo: 91);
        $recent = $this->batch(BatchStatus::Invalid, endedDaysAgo: 89);
        $completed = $this->batch(BatchStatus::Completed, endedDaysAgo: 400);

        $report = static::getContainer()->get(ExportCleaner::class)->run('scheduler');

        self::assertSame(['batches' => 3, 'rows' => 9], ['batches' => $report['purged_batches'], 'rows' => $report['purged_rows']]);
        $rows = static::getContainer()->get(MigrationRowRepository::class);
        foreach ([$rejected, $invalid, $failed] as $batch) {
            self::assertFalse($rows->hasRows($batch), $batch->getStatus()->value.' rows are deleted');
        }
        self::assertTrue($rows->hasRows($recent), 'not yet 90 days');
        self::assertTrue($rows->hasRows($completed), 'a batch that changed core keeps its rows for good');

        // The batch and its counts stay, and the deletion is in its audit trail.
        $this->em()->clear();
        $kept = $this->em()->find(MigrationBatch::class, $invalid->getId());
        self::assertSame(3, $kept->getRowCount());
        $audit = $this->em()->getRepository(AuditEntry::class)->findOneBy(['batch' => $kept, 'action' => 'rows_purged']);
        self::assertStringContainsString('3 row(s) permanently deleted', $audit->getDetails());
        self::assertStringContainsString('cannot be restored', $audit->getDetails());

        // A second run finds nothing left to delete.
        $again = static::getContainer()->get(BatchRowPurger::class)->purge('scheduler');
        self::assertSame(['batches' => 0, 'rows' => 0], $again);
    }

    public function testAnInvalidBatchWhoseRowsWereDeletedCannotProceed(): void
    {
        $batch = $this->batch(BatchStatus::Invalid, endedDaysAgo: 91);
        static::getContainer()->get(BatchRowPurger::class)->purge('scheduler');

        $this->expectException(BatchLocked::class);
        $this->expectExceptionMessage('permanently deleted');
        static::getContainer()->get(BatchWorkflow::class)->submitValidRows($this->em()->find(MigrationBatch::class, $batch->getId()), 'officer');
    }

    public function testTheBatchPageAnnouncesTheDeletionThenReportsIt(): void
    {
        $batch = $this->batch(BatchStatus::Rejected, endedDaysAgo: 10);
        $this->loginAs('officer');

        $this->client->request('GET', '/migration/batches/'.$batch->getId());
        self::assertSelectorTextContains('.exception.notice', 'will be permanently deleted on');

        static::getContainer()->get(MigrationRowRepository::class)->purgeForBatch($batch);
        $this->client->request('GET', '/migration/batches/'.$batch->getId());
        self::assertSelectorTextContains('.exception', 'Rows permanently deleted.');
        self::assertSelectorTextContains('.exception', 'cannot be restored');
    }

    /** A batch of three rows in $status, which ended $endedDaysAgo days ago. */
    private function batch(BatchStatus $status, int $endedDaysAgo): MigrationBatch
    {
        $batch = new MigrationBatch('mapping.csv', 'officer', 3);
        $batch->recordValidated(3, BatchStatus::Invalid === $status ? 1 : 0);
        match ($status) {
            BatchStatus::Failed => $batch->failValidation('Could not be validated.'),
            BatchStatus::Invalid => $batch->finishValidation(2),
            default => $batch->finishValidation(3),
        };
        if (BatchStatus::Rejected === $status) {
            $batch->submit();
            $batch->reject('approver', 'Wrong file');
        }
        if (BatchStatus::Completed === $status) {
            $batch->submit();
            $batch->approve('approver');
            $batch->markCompleted();
        }
        self::assertSame($status, $batch->getStatus());

        $ended = new \DateTimeImmutable(\sprintf('-%d days', $endedDaysAgo));
        foreach (['uploadedAt', 'reviewedAt', 'processedAt'] as $property) {
            $reflection = new \ReflectionProperty(MigrationBatch::class, $property);
            if (null !== $reflection->getValue($batch)) {
                $reflection->setValue($batch, $ended);
            }
        }
        $this->em()->persist($batch);
        $this->em()->flush();

        $rows = [];
        for ($line = 1; $line <= 3; ++$line) {
            $rows[] = new MigrationRow($batch, $line, (string) (1000 + $line), '001-00000000'.$line, '009-00000000'.$line);
        }
        static::getContainer()->get(MigrationRowRepository::class)->insert($batch, $rows);

        return $batch;
    }
}
