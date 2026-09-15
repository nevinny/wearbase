<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandKeyword>
 */
class BrandKeywordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandKeyword::class);
    }

    public function existsForBrand(Brand $brand): bool
    {
        return $this->count(['brand' => $brand]) > 0;
    }

    public function existsPhrase(Brand $brand, string $keyword, string $type): bool
    {
        return $this->count(['brand' => $brand, 'keyword' => $keyword, 'type' => $type]) > 0;
    }

    /**
     * Ключевики бренда: сначала origin, внутри — по убыванию частоты.
     * @return BrandKeyword[]
     */
    public function findByBrandRanked(Brand $brand, int $limit = 8): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.brand = :brand')
            ->andWhere('k.blockedAt IS NULL')  // отсеянные минус-словами не отдаём
            ->setParameter('brand', $brand)
            ->orderBy('k.type', 'ASC')        // origin < related
            ->addOrderBy('k.monthlyShows', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Топ-фраза бренда по частотности (для рубрики demand — ответ на реальный спрос). */
    public function findTopByBrand(Brand $brand): ?BrandKeyword
    {
        return $this->createQueryBuilder('k')
            ->where('k.brand = :brand')
            ->andWhere('k.blockedAt IS NULL')  // отсеянные минус-словами не отдаём
            ->setParameter('brand', $brand)
            ->orderBy('k.monthlyShows', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteForBrand(Brand $brand): void
    {
        $this->createQueryBuilder('k')
            ->delete()
            ->where('k.brand = :brand')
            ->setParameter('brand', $brand)
            ->getQuery()
            ->execute();
    }
}
