<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return Review[]
     */
    public function findApprovedByProduct(Product $product): array
    {
        return $this->findBy(
            ['product' => $product, 'status' => Review::STATUS_APPROVED],
            ['createdAt' => 'DESC']
        );
    }

    public function findPending(): array
    {
        return $this->findBy(['status' => Review::STATUS_PENDING], ['createdAt' => 'DESC']);
    }

    public function getAverageRating(Product $product): ?float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating)')
            ->andWhere('r.product = :product')
            ->andWhere('r.status = :status')
            ->setParameter('product', $product)
            ->setParameter('status', Review::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float) $result : null;
    }

    public function countApproved(Product $product): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.product = :product')
            ->andWhere('r.status = :status')
            ->setParameter('product', $product)
            ->setParameter('status', Review::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
