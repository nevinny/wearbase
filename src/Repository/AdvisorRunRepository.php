<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdvisorRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdvisorRun>
 */
class AdvisorRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdvisorRun::class);
    }

    public function findLatest(): ?AdvisorRun
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.ranAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
