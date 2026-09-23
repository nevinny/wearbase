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

    /**
     * Самые свежие проверки набора фраз ОДНИМ запросом (для 30-дневного скип-кэша
     * app:seo:competitor-scan — раньше дёргалось по одной фразе за раз в цикле кандидатов,
     * до ~40 запросов за прогон при --limit).
     *
     * @param string[] $keywords
     * @return array<string,SeoCompetitorScan> keyword => самый свежий scan (по id)
     */
    public function findLatestByKeywords(array $keywords): array
    {
        if ($keywords === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->where('s.keyword IN (:keywords)')
            ->setParameter('keywords', $keywords)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $scan) {
            $latest[$scan->getKeyword()] = $scan; // ASC → последняя запись на keyword и есть самая свежая
        }

        return $latest;
    }
}
