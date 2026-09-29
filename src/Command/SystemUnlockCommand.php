<?php

namespace App\Command;

use App\Entity\UserAuditEntry;
use App\Security\SystemLock;
use App\Security\UserAudit;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery on the server: lifts the system lock (or cancels a timed one) without the super user,
 * e.g. when its password is lost or the lock file failed its integrity check after APP_SECRET
 * changed. Recorded in the account audit trail as done by "console:<os user>".
 */
#[AsCommand(
    name: 'app:system:unlock',
    description: 'Lift the system lock or cancel a timed lock (recovery, without the super user)',
)]
final class SystemUnlockCommand
{
    public function __construct(
        private readonly SystemLock $lock,
        private readonly UserAudit $audit,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Why the lock is lifted (kept in the audit trail)')] string $reason): int
    {
        if ('' === trim($reason)) {
            $io->error('Give a reason.');

            return Command::INVALID;
        }

        $status = $this->lock->status();
        if (!$status->isLocked() && !$status->isScheduled()) {
            $io->success('The system is not locked and no timed lock is set.');

            return Command::SUCCESS;
        }

        $this->lock->clear();
        $action = $status->isLocked() ? UserAuditEntry::SYSTEM_UNLOCKED : UserAuditEntry::SYSTEM_LOCK_CANCELLED;
        $this->audit->record(UserAudit::consoleActor(), $action, 'system', trim($reason), flush: true);
        $io->success($status->isLocked() ? 'The system was unlocked. Sign-in is open again.' : 'The timed lock was cancelled.');

        return Command::SUCCESS;
    }
}
