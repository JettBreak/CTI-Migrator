<?php

namespace App\Entity;

use App\Repository\MigrationRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an uploaded mapping file: rename core account $currentAccount to $newAccount.
 * $cardRef is optional and only cross-checked; every card linked to the account follows the rename.
 *
 * Rows are written in bulk by MigrationRowRepository::insert(); keep its column list in step with this mapping.
 */
#[ORM\Entity(repositoryClass: MigrationRowRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_migration_row_line', columns: ['batch_id', 'line_number'])]
#[ORM\Index(name: 'idx_migration_row_current', columns: ['batch_id', 'current_account'])]
#[ORM\Index(name: 'idx_migration_row_new', columns: ['batch_id', 'new_account'])]
#[ORM\Index(name: 'idx_migration_row_pending', columns: ['batch_id', 'applied_at', 'current_account'])]
#[ORM\Index(name: 'idx_migration_row_valid', columns: ['batch_id', 'valid', 'line_number'])]
#[ORM\Index(name: 'idx_migration_row_rollback', columns: ['batch_id', 'rolled_back_at', 'current_account'])]
class MigrationRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $errors = [];

    /** No errors; stored on its own so a batch's invalid rows can be counted and listed through an index. */
    #[ORM\Column(options: ['default' => false])]
    private bool $valid = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $cardDisplay = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cardholder = null;

    /** Number of cards linked to current_account in core, i.e. how many cards the rename affects. */
    #[ORM\Column(nullable: true)]
    private ?int $linkedCards = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /** When a rollback gave current_account its number back in core. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rolledBackAt = null;

    /** Why a rollback left this row's account alone (core changed since it was replaced). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rollbackError = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private MigrationBatch $batch,
        #[ORM\Column]
        private int $lineNumber,
        #[ORM\Column(length: 30)]
        private string $cardRef,
        #[ORM\Column(length: 64)]
        private string $currentAccount,
        #[ORM\Column(length: 64)]
        private string $newAccount,
    ) {
    }

    /** @param list<string> $errors */
    public function recordValidation(array $errors, ?string $cardDisplay, ?string $cardholder, ?int $linkedCards): void
    {
        $this->errors = $errors;
        $this->valid = [] === $errors;
        $this->linkedCards = $linkedCards ?? $this->linkedCards;
        $this->cardDisplay = $cardDisplay ?? $this->cardDisplay;
        $this->cardholder = $cardholder ?? $this->cardholder;
    }

    public function markApplied(\DateTimeImmutable $at): void
    {
        $this->appliedAt = $at;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): MigrationBatch
    {
        return $this->batch;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getCardRef(): string
    {
        return $this->cardRef;
    }

    public function getCurrentAccount(): string
    {
        return $this->currentAccount;
    }

    public function getNewAccount(): string
    {
        return $this->newAccount;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getCardDisplay(): ?string
    {
        return $this->cardDisplay;
    }

    public function getCardholder(): ?string
    {
        return $this->cardholder;
    }

    public function getLinkedCards(): ?int
    {
        return $this->linkedCards;
    }

    public function getRolledBackAt(): ?\DateTimeImmutable
    {
        return $this->rolledBackAt;
    }

    public function getRollbackError(): ?string
    {
        return $this->rollbackError;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }
}
