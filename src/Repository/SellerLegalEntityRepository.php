<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\SellerLegalEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SellerLegalEntity>
 */
class SellerLegalEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SellerLegalEntity::class);
    }

    /** Действующее (active) юр.лицо бренда — продавец-of-record на сейчас. */
    public function findActiveForBrand(Brand $brand): ?SellerLegalEntity
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.brand = :brand')
            ->andWhere('e.status = :status')
            ->andWhere('(e.effectiveFrom IS NULL OR e.effectiveFrom <= :today)')
            ->andWhere('(e.effectiveTo IS NULL OR e.effectiveTo >= :today)')
            ->setParameter('brand', $brand)
            ->setParameter('status', SellerLegalEntity::STATUS_ACTIVE)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.effectiveFrom', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
