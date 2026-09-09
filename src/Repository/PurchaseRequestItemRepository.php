<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseRequestItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PurchaseRequestItem> */
class PurchaseRequestItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestItem::class);
    }

    /** @return PurchaseRequestItem[] */
    public function findDeliveredBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('item')
            ->addSelect('request', 'subject', 'family')
            ->join('item.purchaseRequest', 'request')
            ->join('request.subject', 'subject')
            ->join('request.family', 'family')
            ->andWhere('item.status = :status')
            ->andWhere('item.deliveredAt < :before')
            ->setParameter('status', PurchaseRequestItem::STATUS_DELIVERED)
            ->setParameter('before', $before)
            ->orderBy('item.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
