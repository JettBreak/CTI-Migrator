<?php

namespace App\Twig;

use App\Security\SystemLock;
use App\Security\SystemLockStatus;
use Twig\Attribute\AsTwigFunction;

/** system_lock(): the lock's status, for the sign-in page and the countdown banner. */
final class SystemLockExtension
{
    public function __construct(private readonly SystemLock $lock)
    {
    }

    #[AsTwigFunction('system_lock')]
    public function status(): SystemLockStatus
    {
        return $this->lock->status();
    }
}
