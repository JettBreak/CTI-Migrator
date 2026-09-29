<?php

namespace App\Security;

use App\Entity\UserAuditEntry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The break-glass account that locks and unlocks the whole app (see SystemLock).
 *
 * It is not in the app's user table: its username and password hash come from the server
 * configuration (APP_SUPERUSER_USERNAME, APP_SUPERUSER_PASSWORD_HASH), so the app's own
 * administrators cannot create, change or remove it. It never signs in; the credentials are
 * checked each time a lock is set or lifted. Attempts are limited per IP and every failure is
 * written to the account audit trail.
 */
final class SuperUser
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const THROTTLED = 'throttled';

    public function __construct(
        #[Autowire('%env(APP_SUPERUSER_USERNAME)%')] private readonly string $username,
        #[Autowire('%env(APP_SUPERUSER_PASSWORD_HASH)%')] private readonly string $passwordHash,
        private readonly RateLimiterFactoryInterface $superuserLimiter,
        private readonly RequestStack $requests,
        private readonly UserAudit $audit,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->username && '' !== $this->passwordHash;
    }

    public function username(): string
    {
        return $this->username;
    }

    /** Checks the credentials typed for $purpose (e.g. "unlock"); returns one of the constants. */
    public function check(string $username, #[\SensitiveParameter] string $password, string $purpose): string
    {
        $username = mb_substr(trim($username), 0, 180);
        $ip = $this->requests->getMainRequest()?->getClientIp() ?? 'console';

        if (!$this->superuserLimiter->create($ip)->consume()->isAccepted()) {
            $this->audit->record($username, UserAuditEntry::SUPERUSER_THROTTLED, 'system', \sprintf('Too many super user attempts (%s); paused.', $purpose), flush: true);

            return self::THROTTLED;
        }

        // Always hash, even for a wrong username, so the response time does not tell which part was wrong.
        $passwordOk = $this->isConfigured() && (new NativePasswordHasher())->verify($this->passwordHash, $password);
        if (!$this->isConfigured() || !hash_equals($this->username, $username) || !$passwordOk) {
            $this->audit->record($username, UserAuditEntry::SUPERUSER_FAILED, 'system', \sprintf('Wrong super user credentials (%s).', $purpose), flush: true);

            return self::INVALID;
        }

        return self::OK;
    }
}
