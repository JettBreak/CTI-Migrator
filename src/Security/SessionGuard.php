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
 * Runs on every request of a signed-in user, after the firewall:
 *  - ends the session after app.security.idle_timeout seconds without activity, or
 *    app.security.session_lifetime seconds after signing in, whichever comes first;
 *  - sends a user whose password is temporary or expired to the change-password page, and
 *    lets them do nothing else until they have changed it.
 *
 * Background refreshes (the auto-refresh controller sends X-Background-Refresh) do not count as
 * activity, so a page left open does not keep its session alive.
 */
final class SessionGuard
{
    /** Routes a user who must change their password can still reach. */
    private const PASSWORD_CHANGE_ROUTES = ['app_account_password', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly AccountPolicy $policy,
        private readonly UserAudit $audit,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();
        $now = $this->policy->now()->getTimestamp();
        $authAt = $session->get(LoginSubscriber::SESSION_AUTH_AT) ?? $now;
        $lastActivity = $session->get(LoginSubscriber::SESSION_LAST_ACTIVITY) ?? $now;

        $idle = $now - $lastActivity > $this->policy->idleTimeout;
        if ($idle || $now - $authAt > $this->policy->sessionLifetime) {
            $this->audit->record($user->getUsername(), UserAuditEntry::SESSION_EXPIRED, $user->getUsername(), $idle ? 'No activity for too long.' : 'Maximum session length reached.', flush: true);
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login', ['expired' => 1])));

            return;
        }

        $session->set(LoginSubscriber::SESSION_AUTH_AT, $authAt);
        if (!$request->headers->has('X-Background-Refresh')) {
            $session->set(LoginSubscriber::SESSION_LAST_ACTIVITY, $now);
        }

        $route = (string) $request->attributes->get('_route');
        if ($this->policy->needsPasswordChange($user) && !\in_array($route, self::PASSWORD_CHANGE_ROUTES, true) && !str_starts_with($route, '_')) {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_account_password')));
        }
    }
}
