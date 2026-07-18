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

    /**
     * У бренда уже есть FAQ-вопрос про «чей/что за бренд/какой страны» — не плодим
     * дубль в app:seo:aio-remediate (уже отвечено на entity-вопрос).
     */
    public function hasBrandEntityQuestion(Brand $brand): bool
    {
        $count = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.brand = :brand')
            ->andWhere(
                'LOWER(f.question) LIKE :m1 OR LOWER(f.question) LIKE :m2 '
                . 'OR LOWER(f.question) LIKE :m3 OR LOWER(f.question) LIKE :m4',
            )
            ->setParameter('brand', $brand)
            ->setParameter('m1', '%чей%бренд%')
            ->setParameter('m2', '%что за бренд%')
            ->setParameter('m3', '%как%стран%')
            ->setParameter('m4', '%производ%')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
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
