<?php

namespace App\Migration;

use App\Core\CoreAccountGateway;
use App\Entity\AuditEntry;
use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Enum\BatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Drives a batch through its lifecycle and records every step in the audit trail.
 * Access rules (who may do what) live in App\Security\BatchVoter.
 */
final class BatchWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MappingFileParser $parser,
        private readonly MappingValidator $validator,
        private readonly CoreAccountGateway $core,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws InvalidMappingFile */
    public function upload(string $path, string $filename, string $user): MigrationBatch
    {
        $parsed = $this->parser->parse($path);

        $batch = new MigrationBatch(mb_substr($filename, 0, 255), $user);
        foreach ($parsed as $line) {
            new MigrationRow($batch, $line['line'], $line['card_ref'], $line['current_account'], $line['new_account']);
        }
        $this->validator->validate($batch->getRows());
        $batch->refreshValidationStatus();

        $this->em->persist($batch);
        $this->audit($batch, $user, 'uploaded', sprintf('%d rows, %d valid, %d need correction', $batch->getRows()->count(), $batch->validRowCount(), $batch->invalidRowCount()));
        $this->em->flush();

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
     * Approves the batch and renames the accounts in core in a single all-or-nothing transaction.
     * Every row is re-validated against live data, under row locks, before anything is written.
     *
     * @throws BatchLocked
     */
    public function approveAndApply(MigrationBatch $batch, string $approver): void
    {
        $this->underLock($batch, BatchStatus::AwaitingApproval, function () use ($batch, $approver): void {
            // Record the approval first: if the process dies mid-way the batch stays
            // visibly "Processing" for reconciliation instead of silently looking pending.
            $batch->approve($approver);
            $this->audit($batch, $approver, 'approved');
            $this->em->flush();

            try {
                [$accounts, $links] = $this->core->transactional(function () use ($batch, $approver): array {
                    $plans = $this->validator->validate($batch->getRows(), lockForUpdate: true);
                    if ($batch->invalidRowCount() > 0) {
                        throw new StaleBatch(sprintf('%d row(s) no longer match live core data.', $batch->invalidRowCount()));
                    }
                    foreach ($plans as $plan) {
                        $this->core->renameAccount($plan->account, $plan->newAccountNo, $approver);
                    }

                    return [count($plans), array_sum(array_map(static fn (RenamePlan $p) => count($p->account->links), $plans))];
                });
            } catch (\Throwable $e) {
                $reason = $e instanceof StaleBatch
                    ? $e->getMessage().' Nothing was changed; correct the file and upload it again.'
                    : 'Core update failed and was rolled back; nothing was changed.';
                $this->logger->error('Migration batch {id} failed: {message}', ['id' => $batch->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
                $batch->markFailed($reason);
                $this->audit($batch, $approver, 'failed', $reason);
                $this->em->flush();

                return;
            }

            $batch->markCompleted();
            $this->audit($batch, $approver, 'completed', sprintf('%d account(s) renamed in core, %d card link(s) updated', $accounts, $links));
            $this->em->flush();
        });
    }

    /**
     * Serialises every state change (and all core writes) behind one lock, then re-reads the
     * batch so a decision made by a concurrent request is never overwritten.
     *
     * @throws BatchLocked
     */
    private function underLock(MigrationBatch $batch, BatchStatus $expected, callable $work): void
    {
        $lock = $this->lockFactory->createLock('migration-batches', ttl: 600);
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
