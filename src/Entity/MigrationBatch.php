<?php

namespace App\Entity;

use App\Enum\BatchStatus;
use App\Repository\MigrationBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An uploaded account-renumbering file and its lifecycle: validate → submit (maker) → approve/reject (checker) → apply.
 *
 * A batch can hold any number of rows, so it never loads them: the counts below are kept up to date
 * as rows are validated and applied, and rows are read page by page through MigrationRowRepository.
 */
#[ORM\Entity(repositoryClass: MigrationBatchRepository::class)]
class MigrationBatch implements RecordsTimezone
{
    use TimezoneColumn;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: BatchStatus::class)]
    private BatchStatus $status = BatchStatus::Importing;

    /** Rows validated so far; equals $rowCount once validation has finished. */
    #[ORM\Column(options: ['default' => 0])]
    private int $validatedRowCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $invalidRowCount = 0;

    /** Distinct accounts the batch renames (an account can appear once per linked card). */
    #[ORM\Column(options: ['default' => 0])]
    private int $accountCount = 0;

    /** Accounts renamed in core so far, and the card links that followed them. */
    #[ORM\Column(options: ['default' => 0])]
    private int $appliedAccountCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $appliedLinkCount = 0;

    /**
     * Submitted with rows that need correction left out: only accounts whose every row is valid are
     * replaced, and the migrated rows and the rows to correct are exported once it completes.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $skipsInvalidRows = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    /** Rollback (maker-checker): who asked and why, the status it returns to if refused, who decided. */
    #[ORM\Column(length: 32, nullable: true, enumType: BatchStatus::class)]
    private ?BatchStatus $rollbackFrom = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $rollbackRequestedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rollbackRequestedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rollbackReason = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $rollbackReviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rollbackReviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rollbackReviewNote = null;

    /** Accounts given their old number back, and accounts skipped because core changed since (see the rows' rollback errors). */
    #[ORM\Column(options: ['default' => 0])]
    private int $rolledBackAccountCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $rollbackSkippedCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $filename,
        #[ORM\Column(length: 180)]
        private string $uploadedBy,
        /** Mapping rows in the file (counted at upload, before validation). */
        #[ORM\Column(options: ['default' => 0])]
        private int $rowCount,
    ) {
        $this->uploadedAt = new \DateTimeImmutable();
    }

    /** (Re)starts validation from the first row, e.g. after the worker was interrupted. */
    public function startValidation(): void
    {
        $this->assertStatus(BatchStatus::Importing);
        $this->validatedRowCount = 0;
        $this->invalidRowCount = 0;
    }

    public function recordValidated(int $rows, int $invalid): void
    {
        $this->assertStatus(BatchStatus::Importing);
        $this->validatedRowCount += $rows;
        $this->invalidRowCount += $invalid;
    }

    /** Sets the status from the validation results. */
    public function finishValidation(int $accountCount): void
    {
        $this->assertStatus(BatchStatus::Importing);
        $this->rowCount = $this->validatedRowCount;
        $this->accountCount = $accountCount;
        $this->status = $this->rowCount > 0 && 0 === $this->invalidRowCount ? BatchStatus::Validated : BatchStatus::Invalid;
    }

    public function failValidation(string $reason): void
    {
        $this->assertStatus(BatchStatus::Importing);
        $this->status = BatchStatus::Failed;
        $this->processedAt = new \DateTimeImmutable();
        $this->failureReason = $reason;
    }

    public function submit(): void
    {
        $this->assertStatus(BatchStatus::Validated);
        $this->status = BatchStatus::AwaitingApproval;
        $this->submittedAt = new \DateTimeImmutable();
    }

    /**
     * Submits a batch that needs correction without its rows to correct.
     *
     * @param int $accounts accounts none of whose rows need correction: what the batch now replaces
     */
    public function submitValidRowsOnly(int $accounts): void
    {
        $this->assertStatus(BatchStatus::Invalid);
        if ($accounts < 1) {
            throw new \LogicException(sprintf('Batch #%d has no account without rows to correct.', $this->id));
        }
        $this->skipsInvalidRows = true;
        $this->accountCount = $accounts;
        $this->status = BatchStatus::AwaitingApproval;
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function approve(string $reviewer): void
    {
        $this->assertStatus(BatchStatus::AwaitingApproval);
        $this->status = BatchStatus::Processing;
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function reject(string $reviewer, ?string $note): void
    {
        $this->assertStatus(BatchStatus::AwaitingApproval);
        $this->status = BatchStatus::Rejected;
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewNote = $note;
    }

    /** One chunk was committed in core. */
    public function recordApplied(int $accounts, int $links): void
    {
        $this->assertStatus(BatchStatus::Processing);
        $this->appliedAccountCount += $accounts;
        $this->appliedLinkCount += $links;
    }

    public function markCompleted(): void
    {
        $this->assertStatus(BatchStatus::Processing);
        $this->status = BatchStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();
    }

    /** Applying stopped on a chunk: Failed if nothing had been renamed yet, otherwise Halted. */
    public function stopApplying(string $reason): void
    {
        $this->assertStatus(BatchStatus::Processing);
        $this->status = $this->appliedAccountCount > 0 ? BatchStatus::Halted : BatchStatus::Failed;
        $this->processedAt = new \DateTimeImmutable();
        $this->failureReason = $reason;
    }

    /** Carries on with the accounts a halted batch has not renamed yet. */
    public function resume(): void
    {
        $this->assertStatus(BatchStatus::Halted);
        $this->status = BatchStatus::Processing;
        $this->processedAt = null;
        $this->failureReason = null;
    }

    /** After re-validation against live core data found (or cleared) problems. */
    public function setInvalidRowCount(int $count): void
    {
        $this->invalidRowCount = $count;
    }

    /** A migration officer asks for the accounts this batch replaced to get their old numbers back. */
    public function requestRollback(string $user, string $reason): void
    {
        if (!\in_array($this->status, [BatchStatus::Completed, BatchStatus::Halted], true) || 0 === $this->appliedAccountCount) {
            throw new \LogicException(sprintf('Batch #%d has no replaced accounts to roll back.', $this->id));
        }
        $this->rollbackFrom = $this->status;
        $this->status = BatchStatus::RollbackRequested;
        $this->rollbackRequestedBy = $user;
        $this->rollbackRequestedAt = new \DateTimeImmutable();
        $this->rollbackReason = $reason;
        $this->rollbackReviewedBy = $this->rollbackReviewedAt = $this->rollbackReviewNote = null;
    }

    public function approveRollback(string $reviewer): void
    {
        $this->assertStatus(BatchStatus::RollbackRequested);
        $this->status = BatchStatus::RollingBack;
        $this->rollbackReviewedBy = $reviewer;
        $this->rollbackReviewedAt = new \DateTimeImmutable();
        $this->processedAt = null;
        $this->failureReason = null;
    }

    /** The batch goes back to how it was (Completed or Stopped part-way); nothing changes in core. */
    public function rejectRollback(string $reviewer, ?string $note): void
    {
        $this->assertStatus(BatchStatus::RollbackRequested);
        $this->status = $this->rollbackFrom ?? BatchStatus::Completed;
        $this->rollbackReviewedBy = $reviewer;
        $this->rollbackReviewedAt = new \DateTimeImmutable();
        $this->rollbackReviewNote = $note;
    }

    /** One chunk was committed in core. */
    public function recordRolledBack(int $restored, int $skipped): void
    {
        $this->assertStatus(BatchStatus::RollingBack);
        $this->rolledBackAccountCount += $restored;
        $this->rollbackSkippedCount += $skipped;
    }

    public function markRolledBack(): void
    {
        $this->assertStatus(BatchStatus::RollingBack);
        $this->status = BatchStatus::RolledBack;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function stopRollingBack(string $reason): void
    {
        $this->assertStatus(BatchStatus::RollingBack);
        $this->status = BatchStatus::RollbackHalted;
        $this->processedAt = new \DateTimeImmutable();
        $this->failureReason = $reason;
    }

    public function resumeRollback(): void
    {
        $this->assertStatus(BatchStatus::RollbackHalted);
        $this->status = BatchStatus::RollingBack;
        $this->processedAt = null;
        $this->failureReason = null;
    }

    private function assertStatus(BatchStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new \LogicException(sprintf('Batch #%d is "%s", expected "%s".', $this->id, $this->status->value, $expected->value));
        }
    }

    /** Percentage for the progress bar while the batch is validating or applying; capped at 99 until done. */
    public function progress(): int
    {
        if (!$this->status->busy()) {
            return 100;
        }
        [$done, $total] = match ($this->status) {
            BatchStatus::Importing => [$this->validatedRowCount, $this->rowCount],
            BatchStatus::RollingBack => [$this->rolledBackAccountCount + $this->rollbackSkippedCount, $this->appliedAccountCount],
            default => [$this->appliedAccountCount, $this->accountCount],
        };

        return $total > 0 ? min(99, (int) floor($done / $total * 100)) : 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStatus(): BatchStatus
    {
        return $this->status;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getValidatedRowCount(): int
    {
        return $this->validatedRowCount;
    }

    public function validRowCount(): int
    {
        return $this->validatedRowCount - $this->invalidRowCount;
    }

    public function invalidRowCount(): int
    {
        return $this->invalidRowCount;
    }

    public function accountCount(): int
    {
        return $this->accountCount;
    }

    public function getAppliedAccountCount(): int
    {
        return $this->appliedAccountCount;
    }

    public function getAppliedLinkCount(): int
    {
        return $this->appliedLinkCount;
    }

    public function getUploadedBy(): string
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function skipsInvalidRows(): bool
    {
        return $this->skipsInvalidRows;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getRollbackFrom(): ?BatchStatus
    {
        return $this->rollbackFrom;
    }

    public function getRollbackRequestedBy(): ?string
    {
        return $this->rollbackRequestedBy;
    }

    public function getRollbackRequestedAt(): ?\DateTimeImmutable
    {
        return $this->rollbackRequestedAt;
    }

    public function getRollbackReason(): ?string
    {
        return $this->rollbackReason;
    }

    public function getRollbackReviewedBy(): ?string
    {
        return $this->rollbackReviewedBy;
    }

    public function getRollbackReviewedAt(): ?\DateTimeImmutable
    {
        return $this->rollbackReviewedAt;
    }

    public function getRollbackReviewNote(): ?string
    {
        return $this->rollbackReviewNote;
    }

    public function getRolledBackAccountCount(): int
    {
        return $this->rolledBackAccountCount;
    }

    public function getRollbackSkippedCount(): int
    {
        return $this->rollbackSkippedCount;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
