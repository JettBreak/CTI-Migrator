<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person who signs in: a migration officer (maker), a migration approver (checker) or a user
 * administrator. Accounts are never deleted, only disabled, so every name stored on batches and in
 * the audit trails keeps pointing at a real account; the username therefore never changes either.
 *
 * Changes that matter for security end the account's sessions on their next request (see
 * isEqualTo()): a new password, role or status, and a sign-in elsewhere (one session per user).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, RecordsTimezone
{
    use TimezoneColumn;

    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,49}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Set for passwords an administrator handed out: the user has to choose their own first. */
    #[ORM\Column(options: ['default' => true])]
    private bool $mustChangePassword = true;

    #[ORM\Column]
    private \DateTimeImmutable $passwordChangedAt;

    /** Failed sign-ins in a row; the account locks when it reaches the limit. */
    #[ORM\Column(options: ['default' => 0])]
    private int $failedLoginCount = 0;

    /** Locked after too many failed sign-ins, until an administrator unlocks it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastLoginIp = null;

    /** The sign-in before the current one, shown to the user so they can spot one that was not theirs. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $previousLoginAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $previousLoginIp = null;

    /** Identifies the one session allowed to use this account; a new sign-in replaces it. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sessionToken = null;

    /** Created or last re-enabled: the start of the inactivity period when it never signed in since. */
    #[ORM\Column]
    private \DateTimeImmutable $activeSince;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $deactivatedBy = null;

    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $username,
        #[ORM\Column(length: 120)]
        private string $displayName,
        #[ORM\Column(length: 32, enumType: UserRole::class)]
        private UserRole $role,
        #[ORM\Column(length: 180)]
        private string $createdBy,
        \DateTimeImmutable $now,
    ) {
        $this->createdAt = $now;
        $this->activeSince = $now;
        $this->passwordChangedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function changeRole(UserRole $role): void
    {
        $this->role = $role;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param bool $temporary an administrator's reset: the user must change it on their next sign-in
     */
    public function setPassword(string $hash, \DateTimeImmutable $now, bool $temporary): void
    {
        $this->password = $hash;
        $this->passwordChangedAt = $now;
        $this->mustChangePassword = $temporary;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function getPasswordChangedAt(): \DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function disable(string $by, \DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->deactivatedAt = $now;
        $this->deactivatedBy = $by;
        $this->sessionToken = null;
    }

    public function enable(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->activeSince = $now;
        $this->deactivatedAt = null;
        $this->deactivatedBy = null;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function getDeactivatedBy(): ?string
    {
        return $this->deactivatedBy;
    }

    public function isLocked(): bool
    {
        return null !== $this->lockedAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getFailedLoginCount(): int
    {
        return $this->failedLoginCount;
    }

    /** Counts a failed sign-in; returns true when this one locked the account. */
    public function recordFailedLogin(int $limit, \DateTimeImmutable $now): bool
    {
        ++$this->failedLoginCount;
        if (null === $this->lockedAt && $this->failedLoginCount >= $limit) {
            $this->lockedAt = $now;

            return true;
        }

        return false;
    }

    public function unlock(): void
    {
        $this->lockedAt = null;
        $this->failedLoginCount = 0;
    }

    /** A successful sign-in: starts a new session token, which ends any other session of this account. */
    public function recordLogin(?string $ip, string $sessionToken, \DateTimeImmutable $now): void
    {
        $this->previousLoginAt = $this->lastLoginAt;
        $this->previousLoginIp = $this->lastLoginIp;
        $this->lastLoginAt = $now;
        $this->lastLoginIp = $ip;
        $this->failedLoginCount = 0;
        $this->sessionToken = $sessionToken;
    }

    /** Ends every session of this account (their next request goes to the sign-in page). */
    public function endSessions(): void
    {
        $this->sessionToken = null;
    }

    public function getSessionToken(): ?string
    {
        return $this->sessionToken;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getLastLoginIp(): ?string
    {
        return $this->lastLoginIp;
    }

    public function getPreviousLoginAt(): ?\DateTimeImmutable
    {
        return $this->previousLoginAt;
    }

    public function getPreviousLoginIp(): ?string
    {
        return $this->previousLoginIp;
    }

    /** Last sign-in, or when the account was created or re-enabled if it has not signed in since. */
    public function lastActiveAt(): \DateTimeImmutable
    {
        return max($this->lastLoginAt ?? $this->activeSince, $this->activeSince);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    /**
     * Keeps the session from holding the password hash: only a checksum of it is stored, which
     * isEqualTo() compares (the same scheme Symfony uses for users that are not equatable).
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    /**
     * Called on the user stored in the session with the one just reloaded from the database: any
     * difference signs the session out.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $user->username === $this->username
            && ($user->password === $this->password || hash('crc32c', $user->password) === $this->password)
            && $user->role === $this->role
            && $user->active && $this->active === $user->active
            && !$user->isLocked()
            && null !== $user->sessionToken && $user->sessionToken === $this->sessionToken;
    }
}
