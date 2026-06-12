<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandMarket;
use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandMarket>
 */
class BrandMarketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandMarket::class);
    }

    /**
     * Все активные рынки бренда.
     *
     * @return BrandMarket[]
     */
    public function findForBrand(Brand $brand): array
    {
        return $this->createQueryBuilder('bm')
            ->join('bm.country', 'c')
            ->where('bm.brand = :brand')
            ->andWhere('bm.status = :status')
            ->setParameter('brand', $brand)
            ->setParameter('status', BrandMarket::STATUS_ACTIVE)
            ->orderBy('bm.sortOrder', 'ASC')
            ->addOrderBy('c.nameRu', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все бренды, работающие в конкретной стране.
     *
     * @return BrandMarket[]
     */
    public function findForCountry(Country $country, int $limit = 50): array
    {
        return $this->createQueryBuilder('bm')
            ->join('bm.brand', 'b')
            ->where('bm.country = :country')
            ->andWhere('bm.status = :status')
            ->setParameter('country', $country)
            ->setParameter('status', BrandMarket::STATUS_ACTIVE)
            ->orderBy('bm.sortOrder', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Все страны, в которых работает бренд (для отображения флагов).
     *
     * @return Country[]
     */
    public function findCountriesForBrand(Brand $brand): array
    {
        return $this->createQueryBuilder('bm')
            ->select('c')
            ->join('bm.country', 'c')
            ->where('bm.brand = :brand')
            ->andWhere('bm.status != :paused')
            ->setParameter('brand', $brand)
            ->setParameter('paused', BrandMarket::STATUS_PAUSED)
            ->orderBy('bm.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Статистика: сколько брендов в каждой стране.
     *
     * @return array<array{code: string, nameRu: string, flagEmoji: string, count: int}>
     */
    public function countByCountry(): array
    {
        return $this->createQueryBuilder('bm')
            ->select('c.code, c.nameRu, c.flagEmoji, COUNT(bm.id) as cnt')
            ->join('bm.country', 'c')
            ->where('bm.status = :status')
            ->setParameter('status', BrandMarket::STATUS_ACTIVE)
            ->groupBy('c.id')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти маркет для конкретного бренда и страны.
     */
    public function findForBrandAndCountry(Brand $brand, Country $country): ?BrandMarket
    {
        return $this->findOneBy(['brand' => $brand, 'country' => $country]);
    }
}
