<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an uploaded mapping file: rename core account $currentAccount to $newAccount.
 * $cardRef is optional and only cross-checked; every card linked to the account follows the rename.
 */
#[ORM\Entity]
class MigrationRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $errors = [];

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $cardDisplay = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cardholder = null;

    /** Number of cards linked to current_account in core, i.e. how many cards the rename affects. */
    #[ORM\Column(nullable: true)]
    private ?int $linkedCards = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'rows')]
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
        $batch->addRow($this);
    }

    /** @param list<string> $errors */
    public function recordValidation(array $errors, ?string $cardDisplay, ?string $cardholder, ?int $linkedCards): void
    {
        $this->errors = $errors;
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
        return [] === $this->errors;
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

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }
}
