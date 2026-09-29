<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserAuditEntry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs out a signed-in user on their next request once the system is locked, and sends them to
 * the sign-in page, which then shows the lock. New sign-ins are refused by UserChecker.
 * Work already running in the background worker is not touched, so a batch is never left half done.
 */
final class SystemLockGuard
{
    public function __construct(
        private readonly SystemLock $lock,
        private readonly Security $security,
        private readonly UserAudit $audit,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    // After the firewall (8), before SessionGuard (0).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1)]
    public function onRequest(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$user instanceof User || !$this->lock->status()->isLocked()) {
            return;
        }

        $this->audit->record($user->getUsername(), UserAuditEntry::SESSION_EXPIRED, $user->getUsername(), 'The system was locked.', flush: true);
        $this->security->logout(false);
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
