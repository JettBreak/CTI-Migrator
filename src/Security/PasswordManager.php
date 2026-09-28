<?php

namespace App\Security;

use App\Entity\PasswordHistory;
use App\Entity\User;
use App\Repository\PasswordHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Sets passwords (keeping the history that stops reuse) and makes temporary ones. */
final class PasswordManager
{
    private const TEMPORARY_LENGTH = 16;
    /** No look-alike characters (0/O, 1/l/I), so a temporary password can be read out or typed. */
    private const SETS = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*?-_+='];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly PasswordHistoryRepository $history,
        private readonly EntityManagerInterface $em,
        private readonly AccountPolicy $policy,
    ) {
    }

    /**
     * Stores a new password (hashed); the old one goes into the history. Ends the account's other
     * sessions, since the stored hash changes. Flushed by the caller.
     *
     * @param bool $temporary set by an administrator: the user must change it on their next sign-in
     */
    public function change(User $user, #[\SensitiveParameter] string $plain, bool $temporary): void
    {
        if ('' !== $user->getPassword()) {
            $this->em->persist(new PasswordHistory($user, $user->getPassword(), $this->policy->now()));
            $this->history->prune($user, $this->policy->passwordHistory - 1);
        }
        $user->setPassword($this->hasher->hashPassword($user, $plain), $this->policy->now(), $temporary);
    }

    /** Whether $plain is the current password or one of the last few (see app.security.password_history). */
    public function wasUsedBefore(User $user, #[\SensitiveParameter] string $plain): bool
    {
        $hasher = $this->hashers->getPasswordHasher($user);
        $hashes = [$user->getPassword(), ...$this->history->recentHashes($user, $this->policy->passwordHistory - 1)];
        foreach ($hashes as $hash) {
            if ('' !== $hash && $hasher->verify($hash, $plain)) {
                return true;
            }
        }

        return false;
    }

    /** A random password that meets the password policy, for new accounts and resets. */
    public function temporary(): string
    {
        $all = implode('', self::SETS);
        $chars = array_map(static fn (string $set) => $set[random_int(0, \strlen($set) - 1)], self::SETS);
        while (\count($chars) < self::TEMPORARY_LENGTH) {
            $chars[] = $all[random_int(0, \strlen($all) - 1)];
        }
        for ($i = \count($chars) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
