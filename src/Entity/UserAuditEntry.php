<?php

namespace App\Entity;

use App\Repository\UserAuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only record of sign-ins and account administration: who (actor) did what (action) to
 * which account (target), from where and when. The actor is "console:<os user>" for commands run
 * on the server, and the username typed for failed sign-ins.
 */
#[ORM\Entity(repositoryClass: UserAuditEntryRepository::class)]
#[ORM\Index(name: 'idx_user_audit_target', columns: ['target', 'created_at'])]
#[ORM\Index(name: 'idx_user_audit_created', columns: ['created_at'])]
class UserAuditEntry implements RecordsTimezone
{
    use TimezoneColumn;

    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_THROTTLED = 'login_throttled';
    public const LOCKED = 'locked';
    public const LOGOUT = 'logout';
    public const SESSION_EXPIRED = 'session_expired';
    public const PASSWORD_CHANGED = 'password_changed';
    public const CHANGE_REQUESTED = 'change_requested';
    public const CHANGE_APPROVED = 'change_approved';
    public const CHANGE_REJECTED = 'change_rejected';
    public const CHANGE_CANCELLED = 'change_cancelled';
    public const CREATED = 'created';
    public const ROLE_CHANGED = 'role_changed';
    public const DISABLED = 'disabled';
    public const ENABLED = 'enabled';
    public const PASSWORD_RESET = 'password_reset';
    public const UNLOCKED = 'unlocked';
    // The whole app (target "system"), by the super user; see App\Security\SystemLock.
    public const SYSTEM_LOCKED = 'system_locked';
    public const SYSTEM_LOCK_SCHEDULED = 'system_lock_scheduled';
    public const SYSTEM_LOCK_CANCELLED = 'system_lock_cancelled';
    public const SYSTEM_UNLOCKED = 'system_unlocked';
    public const SUPERUSER_FAILED = 'superuser_failed';
    public const SUPERUSER_THROTTLED = 'superuser_throttled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $actor,
        #[ORM\Column(length: 40)]
        private string $action,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $target,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $details,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ip,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $userAgent,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
