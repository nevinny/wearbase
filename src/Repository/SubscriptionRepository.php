<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findActiveByBrand(Brand $brand): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.brand = :brand')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('brand', $brand)
            ->setParameter('statuses', [Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE])
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
