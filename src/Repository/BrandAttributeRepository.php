<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandAttribute>
 */
class BrandAttributeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandAttribute::class);
    }

    /** @return BrandAttribute[] */
    public function findByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand], ['name' => 'ASC']);
    }

    /** Перегенерация: убираем только enrichment-значения (owner/crowd_confirmed не трогаем). */
    public function deleteEnrichmentForBrand(Brand $brand): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.brand = :brand')
            ->andWhere('a.provenance = :prov')
            ->setParameter('brand', $brand)
            ->setParameter('prov', BrandAttribute::PROV_ENRICHMENT)
            ->getQuery()
            ->execute();
    }
}
