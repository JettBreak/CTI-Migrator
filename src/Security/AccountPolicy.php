<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The account safeguards' settings (app.security.* parameters) and the checks built on them. */
final class AccountPolicy
{
    public function __construct(
        private readonly ClockInterface $clock,
        /** Failed sign-ins in a row that lock the account until an administrator unlocks it. */
        #[Autowire('%app.security.max_failed_logins%')] public readonly int $maxFailedLogins,
        /** Passwords older than this must be changed before the app can be used. */
        #[Autowire('%app.security.password_max_age%')] public readonly string $passwordMaxAge,
        /** How many previous passwords cannot be chosen again. */
        #[Autowire('%app.security.password_history%')] public readonly int $passwordHistory,
        /** Seconds without activity after which the session ends. */
        #[Autowire('%app.security.idle_timeout%')] public readonly int $idleTimeout,
        /** Seconds after signing in after which the session ends regardless. */
        #[Autowire('%app.security.session_lifetime%')] public readonly int $sessionLifetime,
        /** Accounts not used for this long are disabled. */
        #[Autowire('%app.security.dormant_after%')] public readonly string $dormantAfter,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function passwordExpired(User $user): bool
    {
        return $user->getPasswordChangedAt() < $this->now()->modify('-'.$this->passwordMaxAge);
    }

    /** Days the current password has been in use. */
    public function passwordAgeDays(User $user): int
    {
        return $user->getPasswordChangedAt()->diff($this->now())->days;
    }

    /** The password max age in days (it is configured as a relative date, e.g. "90 days"). */
    public function passwordMaxAgeDays(): int
    {
        $start = new \DateTimeImmutable('2000-01-01');

        return $start->diff($start->modify('+'.$this->passwordMaxAge))->days;
    }

    /** The account must choose a new password before it can do anything else. */
    public function needsPasswordChange(User $user): bool
    {
        return $user->mustChangePassword() || $this->passwordExpired($user);
    }

    public function dormantBefore(): \DateTimeImmutable
    {
        return $this->now()->modify('-'.$this->dormantAfter);
    }

    public function isDormant(User $user): bool
    {
        return $user->lastActiveAt() < $this->dormantBefore();
    }
}
