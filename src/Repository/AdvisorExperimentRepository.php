<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdvisorExperiment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdvisorExperiment>
 */
class AdvisorExperimentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdvisorExperiment::class);
    }

    /**
     * Эксперименты к оценке: развёрнуты, без вердикта, окно замера истекло.
     * @return AdvisorExperiment[]
     */
    public function findDueForEvaluation(\DateTimeInterface $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.verdict IS NULL')
            ->andWhere('e.deployedAt IS NOT NULL')
            ->orderBy('e.deployedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
