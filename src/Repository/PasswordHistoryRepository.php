<?php

namespace App\Repository;

use App\Entity\PasswordHistory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordHistory> */
class PasswordHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordHistory::class);
    }

    /** @return list<string> the user's previous password hashes, newest first */
    public function recentHashes(User $user, int $limit): array
    {
        return array_map(
            static fn (PasswordHistory $entry) => $entry->getHash(),
            $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit),
        );
    }

    /** Deletes all but the newest $keep entries of the user. */
    public function prune(User $user, int $keep): void
    {
        $old = $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'], null, $keep);
        foreach ($old as $entry) {
            $this->getEntityManager()->remove($entry);
        }
    }
}
