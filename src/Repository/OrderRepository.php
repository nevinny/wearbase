<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /** Суммарная выручка бренда по оплаченным заказам (в RUB). */
    public function sumPaidRevenue(Brand $brand): string
    {
        $sum = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.totalAmount), 0)')
            ->andWhere('o.brand = :brand')
            ->andWhere('o.paymentStatus = :paid')
            ->setParameter('brand', $brand)
            ->setParameter('paid', Order::PAYMENT_PAID)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $sum;
    }

    public function countByBrand(Brand $brand): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.brand = :brand')
            ->setParameter('brand', $brand)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findActiveOrdersForUser(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.customer = :user')
            ->andWhere('o.status NOT IN (:done)')
            ->setParameter('user', $user)
            ->setParameter('done', [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
