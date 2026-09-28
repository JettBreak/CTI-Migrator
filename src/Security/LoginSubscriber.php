<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserAuditEntry;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Sign-in bookkeeping: counts failed attempts (locking the account at the limit), records the
 * sign-in and starts the account's one allowed session, and writes every attempt and sign-out
 * to the account audit trail.
 */
final class LoginSubscriber
{
    public const SESSION_AUTH_AT = '_app.auth_at';
    public const SESSION_LAST_ACTIVITY = '_app.last_activity';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserAudit $audit,
        private readonly AccountPolicy $policy,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = $this->policy->now();
        $user->recordLogin($event->getRequest()->getClientIp(), bin2hex(random_bytes(32)), $now);
        $session = $event->getRequest()->getSession();
        $session->set(self::SESSION_AUTH_AT, $now->getTimestamp());
        $session->set(self::SESSION_LAST_ACTIVITY, $now->getTimestamp());
        $this->audit->record($user->getUsername(), UserAuditEntry::LOGIN, $user->getUsername());
        $this->em->flush();
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $username = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier()
            ?? (string) $event->getRequest()->getPayload()->get('_username', '');
        $username = mb_substr(trim($username), 0, 180);
        $exception = $event->getException();
        // Account-status and unknown-user errors reach here wrapped in a generic "bad credentials".
        $cause = $exception instanceof BadCredentialsException && $exception->getPrevious() instanceof AuthenticationException ? $exception->getPrevious() : $exception;

        if ($cause instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->audit->record($username, UserAuditEntry::LOGIN_THROTTLED, $username, 'Too many attempts; sign-in paused.', flush: true);

            return;
        }

        $user = '' === $username ? null : $this->users->findOneByUsername($username);
        $reason = match (true) {
            $cause instanceof UserNotFoundException || null === $user => 'Unknown username.',
            $cause instanceof AccountStatusException => $cause->getMessage(),
            default => 'Wrong password.',
        };
        $this->audit->record($username, UserAuditEntry::LOGIN_FAILED, $username, $reason);

        // Only wrong passwords count towards the lock; a refused (disabled or locked) account was not tried.
        if ($user && !$cause instanceof AccountStatusException && $user->recordFailedLogin($this->policy->maxFailedLogins, $this->policy->now())) {
            $user->endSessions();
            $this->audit->record('system', UserAuditEntry::LOCKED, $username, sprintf('%d failed sign-ins in a row.', $user->getFailedLoginCount()));
        }
        $this->em->flush();
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->audit->record($user->getUsername(), UserAuditEntry::LOGOUT, $user->getUsername(), flush: true);
        }
    }
}
