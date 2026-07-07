<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StateSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StateSnapshot>
 */
class StateSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StateSnapshot::class);
    }

    /** Последний снимок (для расчёта дельты следующим тиком). */
    public function findLatest(): ?StateSnapshot
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
