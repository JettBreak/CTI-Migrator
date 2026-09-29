<?php

namespace App\Security;

/**
 * The system lock as read from its signed file (see SystemLock), at a given moment.
 *
 * A forced lock is in effect from when it was set; a timed lock lets the app be used until
 * $locksAt, then locks. A file whose signature does not match is "tampered" and counts as locked.
 */
final readonly class SystemLockStatus
{
    public const FORCED = 'forced';
    public const TIMED = 'timed';

    public function __construct(
        private \DateTimeImmutable $now,
        public ?string $mode = null,
        public ?\DateTimeImmutable $locksAt = null,
        public ?string $setBy = null,
        public ?\DateTimeImmutable $setAt = null,
        public ?string $reason = null,
        public bool $tampered = false,
    ) {
    }

    /** No one can sign in, and signed-in users are signed out. */
    public function isLocked(): bool
    {
        return $this->tampered
            || self::FORCED === $this->mode
            || (self::TIMED === $this->mode && $this->now >= $this->locksAt);
    }

    /** A timed lock that has not started yet: the app can still be used until $locksAt. */
    public function isScheduled(): bool
    {
        return self::TIMED === $this->mode && !$this->tampered && $this->now < $this->locksAt;
    }

    /** Whole days until a scheduled lock starts, counting a started day as a day (1 on the last day). */
    public function daysLeft(): ?int
    {
        return $this->isScheduled() ? (int) ceil(($this->locksAt->getTimestamp() - $this->now->getTimestamp()) / 86400) : null;
    }

    /** When the lock took effect. */
    public function lockedSince(): ?\DateTimeImmutable
    {
        return match (true) {
            !$this->isLocked() || $this->tampered => null,
            self::TIMED === $this->mode => $this->locksAt,
            default => $this->setAt,
        };
    }
}
