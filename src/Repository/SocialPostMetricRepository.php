<?php

namespace App\Repository;

use App\Entity\SocialPost;
use App\Entity\SocialPostMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPostMetric>
 */
class SocialPostMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPostMetric::class);
    }

    /** Последний снимок метрик поста (null — ещё не мерили). */
    public function findLatestForPost(SocialPost $post): ?SocialPostMetric
    {
        return $this->createQueryBuilder('m')
            ->where('m.post = :p')
            ->setParameter('p', $post)
            ->orderBy('m.measuredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
