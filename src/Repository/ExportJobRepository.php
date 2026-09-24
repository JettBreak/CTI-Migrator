<?php

namespace App\Repository;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExportJob> */
class ExportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExportJob::class);
    }

    /** @return list<ExportJob> */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return list<ExportJob> */
    public function findInState(ExportState $state, \DateTimeImmutable $createdBefore): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.state = :state')->andWhere('j.createdAt < :before')
            ->setParameter('state', $state)->setParameter('before', $createdBefore)
            ->getQuery()->getResult();
    }

    /** @return list<ExportJob> */
    public function findCompletedBefore(\DateTimeImmutable $finishedBefore): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.state = :state')->andWhere('j.finishedAt < :before')
            ->setParameter('state', ExportState::Completed)->setParameter('before', $finishedBefore)
            ->getQuery()->getResult();
    }

    public function countInState(ExportState $state): int
    {
        return $this->count(['state' => $state]);
    }

    /** The export the worker is generating right now, if any. */
    public function findRunning(): ?ExportJob
    {
        return $this->findOneBy(['state' => ExportState::Running], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function hasUnfinished(): bool
    {
        return $this->count(['state' => [ExportState::Queued, ExportState::Running]]) > 0;
    }
}
