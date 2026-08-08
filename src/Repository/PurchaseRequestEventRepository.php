<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseRequestEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PurchaseRequestEvent> */
class PurchaseRequestEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestEvent::class);
    }
}
