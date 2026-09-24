<?php

namespace App\Entity;

use App\Repository\AuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only record of who did what to a batch. Core also logs each relink itself via sp_auditlog.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private MigrationBatch $batch,
        #[ORM\Column(length: 180)]
        private string $actor,
        #[ORM\Column(length: 40)]
        private string $action,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $details = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): MigrationBatch
    {
        return $this->batch;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
