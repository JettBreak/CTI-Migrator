<?php

namespace App\Repository;

use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Enum\BatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MigrationBatch> */
class MigrationBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MigrationBatch::class);
    }

    /** @return list<MigrationBatch> */
    public function findRecent(int $limit = 50): array
    {
        return $this->findBy([], ['uploadedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function countByStatus(BatchStatus $status): int
    {
        return $this->count(['status' => $status]);
    }

    /** Accounts renamed by completed batches. */
    public function countRenamedAccounts(): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT r.newAccount)')->from(MigrationRow::class, 'r')
            ->where('r.appliedAt IS NOT NULL')
            ->getQuery()->getSingleScalarResult();
    }
}
