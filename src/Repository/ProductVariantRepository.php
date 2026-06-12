<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * @return string[]
     */
    public function findDistinctSizes(): array
    {
        return $this->createQueryBuilder('v')
            ->select('DISTINCT v.size')
            ->join('v.product', 'p')
            ->where('p.status = :pStatus')
            ->andWhere('v.status = :vStatus')
            ->andWhere('v.size IS NOT NULL')
            ->andWhere("v.size != ''")
            ->setParameter('pStatus', 'active')
            ->setParameter('vStatus', 'active')
            ->orderBy('v.size', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
