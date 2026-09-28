<?php

namespace App\Repository;

use App\Entity\UserChangeRequest;
use App\Enum\UserChangeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserChangeRequest> */
class UserChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserChangeRequest::class);
    }

    /** @return list<UserChangeRequest> oldest first */
    public function findPending(): array
    {
        return $this->findBy(['status' => UserChangeStatus::Pending], ['requestedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findPendingFor(string $username): ?UserChangeRequest
    {
        return $this->findOneBy(['targetUsername' => $username, 'status' => UserChangeStatus::Pending]);
    }

    /** @return list<UserChangeRequest> decided requests, newest first */
    public function findDecided(int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status != :pending')
            ->setParameter('pending', UserChangeStatus::Pending)
            ->orderBy('r.reviewedAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<UserChangeRequest> newest first */
    public function findFor(string $username): array
    {
        return $this->findBy(['targetUsername' => $username], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }
}
