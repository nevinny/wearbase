<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandClaim>
 */
class BrandClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandClaim::class);
    }

    public function findPendingByBrandAndUser(Brand $brand, User $user): ?BrandClaim
    {
        return $this->createQueryBuilder('c')
            ->where('c.brand = :brand')
            ->andWhere('c.user = :user')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('brand', $brand)
            ->setParameter('user', $user)
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return BrandClaim[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->orderBy('c.emailDomainMatch', 'DESC') // email-verified first
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
