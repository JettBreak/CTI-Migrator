<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LockedException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses every sign-in while the system is locked (see SystemLock), then disabled, locked and
 * dormant accounts, before the password is even checked. The sign-in page shows the same message
 * for these as for a wrong password (no account enumeration); the reason is kept in the audit trail.
 */
final class UserChecker implements UserCheckerInterface
{
    public function __construct(private readonly AccountPolicy $policy, private readonly SystemLock $systemLock)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ($this->systemLock->status()->isLocked()) {
            throw new CustomUserMessageAccountStatusException('The system is locked.');
        }
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isActive()) {
            throw new DisabledException('Account disabled.');
        }
        if ($user->isLocked()) {
            throw new LockedException('Account locked after too many failed sign-ins.');
        }
        if ($this->policy->isDormant($user)) {
            throw new DisabledException('Account unused for '.$this->policy->dormantAfter.'.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
