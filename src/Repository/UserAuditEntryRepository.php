<?php

namespace App\Repository;

use App\Entity\UserAuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserAuditEntry> */
class UserAuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAuditEntry::class);
    }

    /** @return list<UserAuditEntry> newest first; about one account if $target is given */
    public function findRecent(?string $target = null, int $limit = 200): array
    {
        return $this->findBy(null === $target ? [] : ['target' => $target], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
