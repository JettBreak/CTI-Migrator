<?php

namespace App\Repository;

use App\Entity\AuditEntry;
use App\Entity\MigrationBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEntry> */
class AuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /** @return list<AuditEntry> */
    public function findForBatch(MigrationBatch $batch): array
    {
        return $this->findBy(['batch' => $batch], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
