<?php

namespace App\Entity;

use App\Enum\UserChangeStatus;
use App\Enum\UserChangeType;
use App\Enum\UserRole;
use App\Repository\UserChangeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maker-checker for account administration: one administrator requests a change to an account
 * (or a new account), and a different administrator approves or rejects it. Nothing changes on
 * the account until it is approved. At most one request per account is pending at a time.
 */
#[ORM\Entity(repositoryClass: UserChangeRequestRepository::class)]
#[ORM\Index(name: 'idx_user_change_status', columns: ['status', 'requested_at'])]
#[ORM\Index(name: 'idx_user_change_target', columns: ['target_username', 'status'])]
class UserChangeRequest implements RecordsTimezone
{
    use TimezoneColumn;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: UserChangeStatus::class)]
    private UserChangeStatus $status = UserChangeStatus::Pending;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNote = null;

    /**
     * @param string|null   $displayName for a new account
     * @param UserRole|null $role        for a new account, or the role to change to
     */
    public function __construct(
        #[ORM\Column(length: 20, enumType: UserChangeType::class)]
        private UserChangeType $type,
        #[ORM\Column(length: 180)]
        private string $targetUsername,
        #[ORM\Column(length: 180)]
        private string $requestedBy,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $displayName = null,
        #[ORM\Column(length: 32, nullable: true, enumType: UserRole::class)]
        private ?UserRole $role = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): UserChangeType
    {
        return $this->type;
    }

    public function getTargetUsername(): string
    {
        return $this->targetUsername;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getRole(): ?UserRole
    {
        return $this->role;
    }

    public function getStatus(): UserChangeStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return UserChangeStatus::Pending === $this->status;
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

    /** Human-readable summary, e.g. "Change role to Migration Approver". */
    public function describe(): string
    {
        return match (true) {
            null !== $this->role && \in_array($this->type->value, ['create', 'change_role'], true) => sprintf('%s as %s', $this->type->label(), $this->role->label()),
            default => $this->type->label(),
        };
    }

    public function decide(UserChangeStatus $status, string $by, ?string $note, \DateTimeImmutable $now): void
    {
        if (!$this->isPending()) {
            throw new \LogicException('This request was already decided.');
        }
        $this->status = $status;
        $this->reviewedBy = $by;
        $this->reviewNote = $note;
        $this->reviewedAt = $now;
    }
}
