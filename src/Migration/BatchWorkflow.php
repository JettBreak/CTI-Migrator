<?php

namespace App\Migration;

use App\Core\CoreAccountGateway;
use App\Core\LinkXml;
use App\Entity\AuditEntry;
use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Enum\BatchStatus;
use App\Message\ProcessBatch;
use App\Repository\MigrationRowRepository;
use App\Service\BatchRowPurger;
use App\Service\BatchReportExport;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drives a batch through its lifecycle and records every step in the audit trail.
 * Access rules (who may do what) live in App\Security\BatchVoter.
 *
 * Small batches are validated and applied during the request; larger ones are handed to the
 * background worker (App\Message\ProcessBatch). Either way, applying renames the accounts in
 * chunks: each chunk is re-validated under row locks and committed in its own core transaction.
 */
final class BatchWorkflow
{
    private const LOCK = 'migration-batches';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MappingFileParser $parser,
        private readonly MappingValidator $validator,
        private readonly BatchImporter $importer,
        private readonly MigrationRowRepository $rows,
        private readonly CoreAccountGateway $core,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly BatchReportExport $reports,
        #[Autowire('%app.batch.sync_max_rows%')] private readonly int $syncMaxRows,
        #[Autowire('%app.batch.apply_chunk%')] private readonly int $applyChunk,
        #[Autowire('%app.batch.upload_dir%')] private readonly string $uploadDir,
        private readonly ChunkMemory $memory,
    ) {
    }

    /** @throws InvalidMappingFile when the file cannot be used at all; no batch is created then */
    public function upload(string $path, string $filename, string $user): MigrationBatch
    {
        $batch = new MigrationBatch(mb_substr($filename, 0, 255), $user, $this->parser->inspect($path));
        $this->em->persist($batch);
        $this->audit($batch, $user, 'uploaded', sprintf('%s rows', number_format($batch->getRowCount())));
        $this->em->flush();

        if ($this->runsInRequest($batch)) {
            $this->validate($batch, $path);
        } else {
            // The upload's temporary file is gone after this request; keep a copy for the worker.
            (new Filesystem())->copy($path, $this->storedFile($batch), true);
            $this->bus->dispatch(new ProcessBatch($batch->getId(), $user));
        }

        return $batch;
    }

    /** @throws BatchLocked */
    public function submit(MigrationBatch $batch, string $user): void
    {
        $this->underLock($batch, BatchStatus::Validated, function () use ($batch, $user): void {
            $batch->submit();
            $this->audit($batch, $user, 'submitted');
            $this->em->flush();
        });
    }

    /**
     * Submits a batch that needs correction without its rows to correct: every account with such a row
     * is left out, and once the rest are replaced the migrated rows and the rows to correct are exported.
     *
     * @throws BatchLocked
     */
    public function submitValidRows(MigrationBatch $batch, string $user): void
    {
        $this->underLock($batch, BatchStatus::Invalid, function () use ($batch, $user): void {
            if (!$this->rows->hasRows($batch)) {
                throw new BatchLocked(\sprintf('The rows of this batch were permanently deleted %s after it was found to need correction, so it can no longer proceed. Upload the file again.', BatchRowPurger::RETENTION));
            }
            $accounts = $this->rows->countFullyValidAccounts($batch);
            if (0 === $accounts) {
                throw new BatchLocked('Every account in this batch has a row that needs correction, so there is nothing to replace. Correct the file and upload it again.');
            }
            $batch->submitValidRowsOnly($accounts);
            $this->audit($batch, $user, 'submitted', sprintf('Valid rows only: %d account(s) to replace; %d row(s) that need correction are skipped', $accounts, $batch->invalidRowCount()));
            $this->em->flush();
        });
    }

    /** @throws BatchLocked */
    public function reject(MigrationBatch $batch, string $user, ?string $note): void
    {
        $this->underLock($batch, BatchStatus::AwaitingApproval, function () use ($batch, $user, $note): void {
            $batch->reject($user, $note);
            $this->audit($batch, $user, 'rejected', $note);
            $this->em->flush();
        });
    }

    /**
     * Approves the batch and starts renaming its accounts in core (see applyPending()).
     *
     * @throws BatchLocked
     */
    public function approve(MigrationBatch $batch, string $approver): void
    {
        $this->underLock($batch, BatchStatus::AwaitingApproval, function () use ($batch, $approver): void {
            // Record the approval first: if the process dies mid-way the batch stays
            // visibly "Processing" for reconciliation instead of silently looking pending.
            $batch->approve($approver);
            $this->audit($batch, $approver, 'approved');
            $this->em->flush();
        });
        $this->startApplying($batch, $approver);
    }

    /**
     * Carries on renaming the accounts a halted batch has not renamed yet.
     *
     * @throws BatchLocked
     */
    public function resume(MigrationBatch $batch, string $approver): void
    {
        $this->underLock($batch, BatchStatus::Halted, function () use ($batch, $approver): void {
            $batch->resume();
            $this->audit($batch, $approver, 'resumed', sprintf('%d of %d account(s) already replaced', $batch->getAppliedAccountCount(), $batch->accountCount()));
            $this->em->flush();
        });
        $this->startApplying($batch, $approver);
    }

    /**
     * Maker: a migration officer asks for every account this batch replaced to get its old number back.
     *
     * @throws BatchLocked
     */
    public function requestRollback(MigrationBatch $batch, string $user, string $reason): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK, ttl: 600);
        if (!$lock->acquire()) {
            throw new BatchLocked('Another batch is being processed right now. Try again in a moment.');
        }
        try {
            $this->em->refresh($batch);
            if (!\in_array($batch->getStatus(), [BatchStatus::Completed, BatchStatus::Halted], true)) {
                throw new BatchLocked(sprintf('This batch is now "%s"; reload the page.', $batch->getStatus()->label()));
            }
            $batch->requestRollback($user, $reason);
            $this->audit($batch, $user, 'rollback requested', $reason);
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }

    /**
     * Checker: an approver approves the rollback and it starts (in the request, or in the worker for large batches).
     *
     * @throws BatchLocked
     */
    public function approveRollback(MigrationBatch $batch, string $approver): void
    {
        $this->underLock($batch, BatchStatus::RollbackRequested, function () use ($batch, $approver): void {
            $batch->approveRollback($approver);
            $this->audit($batch, $approver, 'rollback approved', sprintf('%d account(s) to give their old number back', $batch->getAppliedAccountCount()));
            $this->em->flush();
        });
        $this->startRollingBack($batch, $approver);
    }

    /** @throws BatchLocked */
    public function rejectRollback(MigrationBatch $batch, string $approver, ?string $note): void
    {
        $this->underLock($batch, BatchStatus::RollbackRequested, function () use ($batch, $approver, $note): void {
            $batch->rejectRollback($approver, $note);
            $this->audit($batch, $approver, 'rollback rejected', $note);
            $this->em->flush();
        });
    }

    /** @throws BatchLocked */
    public function resumeRollback(MigrationBatch $batch, string $approver): void
    {
        $this->underLock($batch, BatchStatus::RollbackHalted, function () use ($batch, $approver): void {
            $batch->resumeRollback();
            $this->audit($batch, $approver, 'rollback resumed', sprintf('%d of %d account(s) already dealt with', $batch->getRolledBackAccountCount() + $batch->getRollbackSkippedCount(), $batch->getAppliedAccountCount()));
            $this->em->flush();
        });
        $this->startRollingBack($batch, $approver);
    }

    /** Background step (App\Message\ProcessBatch): continue the batch from wherever it is. */
    public function process(int $batchId, string $actor): void
    {
        $batch = $this->em->find(MigrationBatch::class, $batchId);
        match ($batch?->getStatus()) {
            BatchStatus::Importing => $this->validate($batch, $this->storedFile($batch)),
            BatchStatus::Processing => $this->applyPending($batch, $actor),
            BatchStatus::RollingBack => $this->rollbackPending($batch, $actor),
            default => null, // deleted, or already handled (e.g. a duplicate delivery)
        };
    }

    private function validate(MigrationBatch $batch, string $path): void
    {
        try {
            $this->importer->import($batch, $path);
            $this->audit($batch, $batch->getUploadedBy(), 'validated', sprintf('%d rows, %d valid, %d need correction', $batch->getRowCount(), $batch->validRowCount(), $batch->invalidRowCount()));
        } catch (\Throwable $e) {
            $this->logger->error('Validating migration batch {id} failed: {message}', ['id' => $batch->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            $reason = 'The file could not be validated; nothing was changed. Upload it again, and check the application log if this keeps happening.';
            $batch->failValidation($reason);
            $this->audit($batch, $batch->getUploadedBy(), 'failed', $reason);
        } finally {
            if ($path === $this->storedFile($batch)) {
                (new Filesystem())->remove($path);
            }
        }
        $this->em->flush();
    }

    private function startApplying(MigrationBatch $batch, string $actor): void
    {
        if ($this->runsInRequest($batch)) {
            $this->applyPending($batch, $actor);
        } else {
            $this->bus->dispatch(new ProcessBatch($batch->getId(), $actor));
        }
    }

    /**
     * Renames the batch's remaining accounts in core, $applyChunk accounts per transaction. Each chunk is
     * re-validated against live data, under row locks, before anything is written, and commits on its own.
     *
     * If a chunk fails, it is rolled back and the batch stops there: Failed when nothing had been renamed
     * yet, otherwise Halted with the earlier chunks renamed (an approver can resume it later).
     */
    private function applyPending(MigrationBatch $batch, string $actor): void
    {
        while ([] !== $accounts = $this->rows->nextPendingAccounts($batch, $this->applyChunk)) {
            // Taken per chunk, so other batches can still be submitted or reviewed during a long run.
            $lock = $this->lockFactory->createLock(self::LOCK, ttl: 600);
            $lock->acquire(true);
            try {
                $this->em->refresh($batch);
                if (BatchStatus::Processing !== $batch->getStatus()) {
                    return; // finished or stopped by a concurrent run
                }
                $rows = $this->rows->pendingRowsFor($batch, $accounts);

                try {
                    [$renamed, $links] = $this->core->transactional(function () use ($batch, $rows, $actor): array {
                        [$firstForCurrent, $firstForNew] = $this->rows->firstMappings(
                            $batch,
                            array_map(static fn (MigrationRow $r) => $r->getCurrentAccount(), $rows),
                            array_map(static fn (MigrationRow $r) => $r->getNewAccount(), $rows),
                        );
                        $plans = $this->validator->validate($rows, true, $firstForCurrent, $firstForNew);
                        $invalid = count(array_filter($rows, static fn (MigrationRow $r) => !$r->isValid()));
                        if ($invalid > 0) {
                            throw new StaleBatch(sprintf('%d row(s) no longer match live core data.', $invalid));
                        }
                        foreach ($plans as $plan) {
                            $this->core->renameAccount($plan->account, $plan->newAccountNo, $actor);
                        }

                        return [count($plans), array_sum(array_map(static fn (RenamePlan $p) => count($p->account->links), $plans))];
                    });
                } catch (\Throwable $e) {
                    $this->stopApplying($batch, $actor, $e);

                    return;
                }

                if (0 === $this->rows->markApplied($batch, $accounts, new \DateTimeImmutable())) {
                    throw new \LogicException(sprintf('Batch #%d: no rows marked applied for the chunk starting at %s.', $batch->getId(), $accounts[0]));
                }
                $batch->recordApplied($renamed, $links);
                $this->em->flush();
                array_map($this->em->detach(...), $rows);
            } finally {
                $lock->release();
            }
            $this->memory->release();
        }

        $this->em->refresh($batch);
        if (BatchStatus::Processing !== $batch->getStatus()) {
            return;
        }
        $batch->setInvalidRowCount($this->rows->stats($batch)['invalid']);
        $batch->markCompleted();
        $this->audit($batch, $actor, 'completed', sprintf('%d account(s) replaced in core, %d card link(s) updated', $batch->getAppliedAccountCount(), $batch->getAppliedLinkCount()));
        $this->em->flush();

        if ($batch->skipsInvalidRows()) {
            [$migrated, $corrections] = $this->reports->queue($batch, $actor);
            $this->audit($batch, $actor, 'exports queued', sprintf('Export #%d (migrated rows) and export #%d (rows to correct)', $migrated->getId(), $corrections->getId()));
            $this->em->flush();
        }
    }

    private function startRollingBack(MigrationBatch $batch, string $actor): void
    {
        if ($this->runsInRequest($batch)) {
            $this->rollbackPending($batch, $actor);
        } else {
            $this->bus->dispatch(new ProcessBatch($batch->getId(), $actor));
        }
    }

    /**
     * Gives the batch's replaced accounts their old numbers back, $applyChunk accounts per core transaction.
     *
     * Each account is checked under row locks first: its new number must still be exactly one account
     * record, its old number must be free, and every card link must still be exactly as the replacement
     * left it (LinkXml::revert()). An account that fails a check is left alone; the reason is kept on its
     * rows and exported afterwards. An unexpected error rolls the chunk back and stops the rollback
     * (RollbackHalted; an approver can resume it).
     */
    private function rollbackPending(MigrationBatch $batch, string $actor): void
    {
        while ([] !== $accounts = $this->rows->nextRollbackAccounts($batch, $this->applyChunk)) {
            $lock = $this->lockFactory->createLock(self::LOCK, ttl: 600);
            $lock->acquire(true);
            try {
                $this->em->refresh($batch);
                if (BatchStatus::RollingBack !== $batch->getStatus()) {
                    return; // finished or stopped by a concurrent run
                }
                $numbers = $this->rows->replacedNumbers($batch, $accounts); // old => new

                try {
                    [$restored, $skipped] = $this->core->transactional(fn (): array => $this->restoreChunk($numbers, $actor));
                } catch (\Throwable $e) {
                    $this->stopRollingBack($batch, $actor, $e);

                    return;
                }

                $this->rows->markRolledBack($batch, $restored, new \DateTimeImmutable());
                foreach ($skipped as $old => $reason) {
                    $this->rows->markRollbackSkipped($batch, (string) $old, $reason);
                }
                $batch->recordRolledBack(\count($restored), \count($skipped));
                $this->em->flush();
            } finally {
                $lock->release();
            }
            $this->memory->release();
        }

        $this->em->refresh($batch);
        if (BatchStatus::RollingBack !== $batch->getStatus()) {
            return;
        }
        $batch->markRolledBack();
        $this->audit($batch, $actor, 'rolled back', sprintf('%d account(s) got their old number back; %d left alone because core changed since', $batch->getRolledBackAccountCount(), $batch->getRollbackSkippedCount()));
        $this->em->flush();

        if ($batch->getRollbackSkippedCount() > 0) {
            $job = $this->reports->queueRollbackSkipped($batch, $actor, $this->rows->countRollback($batch)['skipped']);
            $this->audit($batch, $actor, 'exports queued', sprintf('Export #%d (accounts not rolled back)', $job->getId()));
            $this->em->flush();
        }
    }

    /**
     * One rollback chunk, inside the core transaction.
     *
     * @param array<string, string> $numbers old number => new number
     *
     * @return array{list<string>, array<string, string>} old numbers given back, and old number => why it was left alone
     */
    private function restoreChunk(array $numbers, string $actor): array
    {
        $current = $this->core->findAccounts(array_values($numbers), true);
        $taken = array_flip($this->core->findUsedKeys(array_map('strval', array_keys($numbers)), true));
        $restored = [];
        $skipped = [];
        foreach ($numbers as $old => $new) {
            $old = (string) $old;
            $records = $current[$new] ?? [];
            $reason = match (true) {
                [] === $records => sprintf('%s is no longer an account number in core.', $new),
                \count($records) > 1 => sprintf('%s now matches %d core account records.', $new, \count($records)),
                isset($taken[$old]) => sprintf('%s is in use by another account again.', $old),
                $this->hasChangedLink($records[0]->links, $new, $old) => 'A card link changed since the replacement.',
                default => null,
            };
            if (null !== $reason) {
                $skipped[$old] = $reason;
                continue;
            }
            $this->core->restoreAccount($records[0], $old, $actor);
            $restored[] = $old;
        }

        return [$restored, $skipped];
    }

    /** @param list<\App\Core\CoreAccountLink> $links */
    private function hasChangedLink(array $links, string $new, string $old): bool
    {
        foreach ($links as $link) {
            if (null === LinkXml::revert($link->xml, $new, $old)) {
                return true;
            }
        }

        return false;
    }

    private function stopRollingBack(MigrationBatch $batch, string $actor, \Throwable $e): void
    {
        $this->logger->error('Rolling back migration batch {id} failed: {message}', ['id' => $batch->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
        $reason = sprintf(
            'Core update failed and was rolled back. Stopped after %d of %d account(s): those are done and the rest still have their new number. Fix the cause and resume the rollback.',
            $batch->getRolledBackAccountCount() + $batch->getRollbackSkippedCount(), $batch->getAppliedAccountCount(),
        );
        $batch->stopRollingBack($reason);
        $this->audit($batch, $actor, 'rollback halted', $reason);
        $this->em->flush();
    }

    private function stopApplying(MigrationBatch $batch, string $actor, \Throwable $e): void
    {
        $this->logger->error('Migration batch {id} failed: {message}', ['id' => $batch->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
        $this->em->flush(); // the errors re-validation recorded on the chunk's rows
        $batch->setInvalidRowCount($this->rows->stats($batch)['invalid']);

        $cause = $e instanceof StaleBatch ? $e->getMessage() : 'Core update failed and was rolled back.';
        if (0 === $batch->getAppliedAccountCount()) {
            $reason = $e instanceof StaleBatch
                ? $cause.' Nothing was changed; correct the file and upload it again.'
                : 'Core update failed and was rolled back; nothing was changed.';
        } else {
            $reason = sprintf(
                '%s Stopped after replacing %d of %d account(s); those stay replaced and the rest were not changed. Fix the cause and resume, or upload the rows not replaced yet as a new batch.',
                $cause, $batch->getAppliedAccountCount(), $batch->accountCount(),
            );
        }
        $batch->stopApplying($reason);
        $this->audit($batch, $actor, BatchStatus::Halted === $batch->getStatus() ? 'halted' : 'failed', $reason);
        $this->em->flush();
    }

    private function runsInRequest(MigrationBatch $batch): bool
    {
        return $batch->getRowCount() <= $this->syncMaxRows;
    }

    private function storedFile(MigrationBatch $batch): string
    {
        return sprintf('%s/batch-%d.csv', $this->uploadDir, $batch->getId());
    }

    /**
     * Serialises every state change (and all core writes) behind one lock, then re-reads the
     * batch so a decision made by a concurrent request is never overwritten.
     *
     * @throws BatchLocked
     */
    private function underLock(MigrationBatch $batch, BatchStatus $expected, callable $work): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK, ttl: 600);
        if (!$lock->acquire()) {
            throw new BatchLocked('Another batch is being processed right now. Try again in a moment.');
        }

        try {
            $this->em->refresh($batch);
            if ($expected !== $batch->getStatus()) {
                throw new BatchLocked(sprintf('This batch is now "%s"; reload the page.', $batch->getStatus()->label()));
            }
            $work();
        } finally {
            $lock->release();
        }
    }

    private function audit(MigrationBatch $batch, string $actor, string $action, ?string $details = null): void
    {
        $this->em->persist(new AuditEntry($batch, $actor, $action, $details));
    }
}
