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

    /**
     * Значения атрибута бренда по имени (category|material|…), в порядке id — детерминированно
     * для слайдов-фактов SlideScriptComposer (не должны на каждый прогон тасоваться местами).
     *
     * @return list<string>
     */
    public function findValuesByBrandAndName(Brand $brand, string $name): array
    {
        return array_column(
            $this->createQueryBuilder('a')
                ->select('a.value')
                ->where('a.brand = :brand')
                ->andWhere('a.name = :name')
                ->setParameter('brand', $brand)
                ->setParameter('name', $name)
                ->orderBy('a.id', 'ASC')
                ->getQuery()
                ->getArrayResult(),
            'value',
        );
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
