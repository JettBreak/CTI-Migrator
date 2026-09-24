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
class MigrationBatch
{
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
        [$done, $total] = BatchStatus::Importing === $this->status
            ? [$this->validatedRowCount, $this->rowCount]
            : [$this->appliedAccountCount, $this->accountCount];

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

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
