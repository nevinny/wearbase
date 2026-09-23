<?php

namespace App\Repository;

use App\Entity\SeoCompetitorScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoCompetitorScan>
 */
class SeoCompetitorScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoCompetitorScan::class);
    }

    /** Самая свежая проверка фразы (для 30-дневного скип-кэша app:seo:competitor-scan). */
    public function findLatestByKeyword(string $keyword): ?SeoCompetitorScan
    {
        return $this->createQueryBuilder('s')
            ->where('s.keyword = :keyword')
            ->setParameter('keyword', $keyword)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
