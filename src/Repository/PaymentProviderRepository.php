<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentProvider>
 */
class PaymentProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentProvider::class);
    }

    /** @return PaymentProvider[] Активные платёжки в порядке сортировки. */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
