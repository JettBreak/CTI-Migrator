<?php

namespace App\Security;

use App\Entity\UserAuditEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** Writes the account audit trail, adding the client's IP and browser when there is a request. */
final class UserAudit
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Adds an entry; it is saved with the caller's next flush (or now, with $flush). */
    public function record(string $actor, string $action, ?string $target = null, ?string $details = null, bool $flush = false): void
    {
        $request = $this->requests->getMainRequest();
        $agent = $request?->headers->get('User-Agent');

        $this->em->persist(new UserAuditEntry(
            mb_substr($actor, 0, 180),
            $action,
            null === $target ? null : mb_substr($target, 0, 180),
            $details,
            $request?->getClientIp(),
            null === $agent ? null : mb_substr($agent, 0, 255),
            $this->clock->now(),
        ));
        if ($flush) {
            $this->em->flush();
        }
    }

    /** The actor for console commands: the operating-system account that ran them. */
    public static function consoleActor(): string
    {
        $user = getenv('USERNAME') ?: getenv('USER') ?: (\function_exists('posix_getuid') ? (string) posix_getuid() : 'unknown');

        return 'console:'.$user;
    }
}
