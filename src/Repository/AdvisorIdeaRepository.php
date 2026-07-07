<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdvisorIdea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdvisorIdea>
 */
class AdvisorIdeaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdvisorIdea::class);
    }

    /** Дедуп против ВСЕХ прошлых идей, включая отклонённые (docs/advisor.md §Дедуп). */
    public function findByDedupeHash(string $hash): ?AdvisorIdea
    {
        return $this->findOneBy(['dedupeHash' => $hash]);
    }

    /**
     * Топ-k нерассмотренных идей по ICE для выбора в тике.
     * @return AdvisorIdea[]
     */
    public function findTopProposed(int $limit = 3): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.status = :status')
            ->setParameter('status', AdvisorIdea::STATUS_PROPOSED)
            ->orderBy('i.iceScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
