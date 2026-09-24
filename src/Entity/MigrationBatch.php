<?php

namespace App\Entity;

use App\Enum\BatchStatus;
use App\Repository\MigrationBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An uploaded account-renumbering file and its lifecycle: validate → submit (maker) → approve/reject (checker) → apply.
 */
#[ORM\Entity(repositoryClass: MigrationBatchRepository::class)]
class MigrationBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: BatchStatus::class)]
    private BatchStatus $status = BatchStatus::Invalid;

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

    /** @var Collection<int, MigrationRow> */
    #[ORM\OneToMany(targetEntity: MigrationRow::class, mappedBy: 'batch', cascade: ['persist'])]
    #[ORM\OrderBy(['lineNumber' => 'ASC'])]
    private Collection $rows;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $filename,
        #[ORM\Column(length: 180)]
        private string $uploadedBy,
    ) {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->rows = new ArrayCollection();
    }

    public function addRow(MigrationRow $row): void
    {
        $this->rows->add($row);
    }

    /** Sets the status from the current row validation results. */
    public function refreshValidationStatus(): void
    {
        $this->status = !$this->rows->isEmpty() && 0 === $this->invalidRowCount() ? BatchStatus::Validated : BatchStatus::Invalid;
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

    public function markCompleted(): void
    {
        $this->assertStatus(BatchStatus::Processing);
        $this->status = BatchStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();
        foreach ($this->rows as $row) {
            $row->markApplied($this->processedAt);
        }
    }

    public function markFailed(string $reason): void
    {
        $this->assertStatus(BatchStatus::Processing);
        $this->status = BatchStatus::Failed;
        $this->processedAt = new \DateTimeImmutable();
        $this->failureReason = $reason;
    }

    private function assertStatus(BatchStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new \LogicException(sprintf('Batch #%d is "%s", expected "%s".', $this->id, $this->status->value, $expected->value));
        }
    }

    public function validRowCount(): int
    {
        return $this->rows->filter(static fn (MigrationRow $row) => $row->isValid())->count();
    }

    /** Distinct accounts the batch renames (an account can appear once per linked card). */
    public function accountCount(): int
    {
        return count(array_unique($this->rows->map(static fn (MigrationRow $row) => $row->getCurrentAccount())->toArray()));
    }

    public function invalidRowCount(): int
    {
        return $this->rows->count() - $this->validRowCount();
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

    /** @return Collection<int, MigrationRow> */
    public function getRows(): Collection
    {
        return $this->rows;
    }
}
