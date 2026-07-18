<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandFaq;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandFaq>
 */
class BrandFaqRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandFaq::class);
    }

    /** @return BrandFaq[] */
    public function findByBrandOrdered(Brand $brand, string $locale = 'ru'): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.brand = :brand')
            ->andWhere('f.locale = :locale')
            ->setParameter('brand', $brand)
            ->setParameter('locale', $locale)
            ->orderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Перегенерация: delete-and-replace по бренду. */
    public function deleteForBrand(Brand $brand): void
    {
        $this->createQueryBuilder('f')
            ->delete()
            ->where('f.brand = :brand')
            ->setParameter('brand', $brand)
            ->getQuery()
            ->execute();
    }
}
