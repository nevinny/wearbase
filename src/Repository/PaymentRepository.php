<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return Payment[]
     */
    public function findBySubscription(Subscription $subscription): array
    {
        return $this->findBy(['subscription' => $subscription], ['createdAt' => 'DESC']);
    }
}
