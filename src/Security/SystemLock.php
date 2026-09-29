<?php

namespace App\Security;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * The lock on the whole app, set and lifted by the super user (see SuperUser).
 *
 * Kept in a file on the server rather than in the app database, signed with a key derived from
 * APP_SECRET, so it cannot be lifted by editing the database. A file that is unreadable or whose
 * signature does not match counts as locked. No file means no lock: removing it takes access to the
 * server, which also gives access to the configuration and to app:system:unlock.
 */
final class SystemLock
{
    private const SIGNING_CONTEXT = 'coreware-system-lock-v1';
    private const DATE_FORMAT = \DateTimeInterface::ATOM;

    public function __construct(
        #[Autowire('%app.system_lock.file%')] private readonly string $file,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly ClockInterface $clock,
        private readonly LockFactory $locks,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function status(): SystemLockStatus
    {
        $now = $this->clock->now();
        if (!is_file($this->file)) {
            return new SystemLockStatus($now);
        }

        $content = @file_get_contents($this->file);
        $data = false === $content ? null : json_decode($content, true);
        $state = \is_array($data) && \is_array($data['state'] ?? null) ? $data['state'] : null;
        if (null === $state || !\is_string($data['signature'] ?? null) || !hash_equals($this->sign($state), $data['signature'])) {
            return new SystemLockStatus($now, tampered: true);
        }

        $locksAt = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, (string) ($state['locksAt'] ?? ''));
        $setAt = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, (string) ($state['setAt'] ?? ''));
        if (!\in_array($state['mode'] ?? null, [SystemLockStatus::FORCED, SystemLockStatus::TIMED], true) || !$locksAt || !$setAt) {
            return new SystemLockStatus($now, tampered: true);
        }

        return new SystemLockStatus($now, $state['mode'], $locksAt, (string) ($state['setBy'] ?? ''), $setAt, (string) ($state['reason'] ?? ''));
    }

    public function lockNow(string $actor, string $reason): void
    {
        $now = $this->clock->now();
        $this->write(SystemLockStatus::FORCED, $now, $actor, $reason);
    }

    /** Lets the app be used for $days more days, then locks it. */
    public function lockAfterDays(int $days, string $actor, string $reason): \DateTimeImmutable
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('A timed lock needs at least one day.');
        }
        $locksAt = $this->clock->now()->modify(\sprintf('+%d days', $days));
        $this->write(SystemLockStatus::TIMED, $locksAt, $actor, $reason);

        return $locksAt;
    }

    /** Lifts a lock, or cancels a timed lock that has not started. */
    public function clear(): void
    {
        $lock = $this->locks->createLock('system-lock');
        $lock->acquire(true);
        try {
            $this->filesystem->remove($this->file);
        } finally {
            $lock->release();
        }
    }

    private function write(string $mode, \DateTimeImmutable $locksAt, string $actor, string $reason): void
    {
        $state = [
            'mode' => $mode,
            'locksAt' => $locksAt->format(self::DATE_FORMAT),
            'setBy' => $actor,
            'setAt' => $this->clock->now()->format(self::DATE_FORMAT),
            'reason' => $reason,
        ];

        $lock = $this->locks->createLock('system-lock');
        $lock->acquire(true);
        try {
            // dumpFile writes a temporary file and renames it, so a reader never sees half a file.
            $this->filesystem->dumpFile($this->file, json_encode(['state' => $state, 'signature' => $this->sign($state)], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $state */
    private function sign(array $state): string
    {
        return hash_hmac('sha256', json_encode($state, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), self::SIGNING_CONTEXT.'|'.$this->secret);
    }
}
