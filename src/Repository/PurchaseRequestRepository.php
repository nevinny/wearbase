<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PurchaseRequest> */
class PurchaseRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequest::class);
    }

    /** @return PurchaseRequest[] */
    public function findVisibleTo(User $actor): array
    {
        $qb = $this->createQueryBuilder('request')
            ->addSelect('subject', 'createdBy', 'decidedBy')
            ->join('request.subject', 'subject')
            ->join('request.createdBy', 'createdBy')
            ->leftJoin('request.decidedBy', 'decidedBy')
            ->orderBy('request.createdAt', 'DESC');

        if ($actor->isFamilyParent() && $actor->getFamily() !== null) {
            $qb->andWhere('request.family = :family')->setParameter('family', $actor->getFamily());
        } else {
            $qb->andWhere('request.subject = :actor')->setParameter('actor', $actor);
        }

        return $qb->getQuery()->getResult();
    }

    public function approvedAmountForMonth(User $subject, \DateTimeImmutable $month, ?PurchaseRequestItem $exclude = null): string
    {
        $from = $month->modify('first day of this month')->setTime(0, 0);
        $to = $from->modify('first day of next month');

        $query = $this->createQueryBuilder('request')
            ->select('COALESCE(SUM(COALESCE(item.actualPrice, item.estimatedPrice)), 0)')
            ->join('request.items', 'item')
            ->andWhere('request.subject = :subject')
            ->andWhere('item.status IN (:statuses)')
            ->andWhere('item.decidedAt >= :from AND item.decidedAt < :to')
            ->setParameter('subject', $subject)
            ->setParameter('statuses', [
                PurchaseRequestItem::STATUS_APPROVED,
                PurchaseRequestItem::STATUS_ORDERED,
                PurchaseRequestItem::STATUS_DELIVERED,
                PurchaseRequestItem::STATUS_BOUGHT,
            ])
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        if ($exclude !== null) {
            $query->andWhere('item != :exclude')->setParameter('exclude', $exclude);
        }

        return (string) $query->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingVisibleTo(User $actor): int
    {
        $qb = $this->createQueryBuilder('request')
            ->select('COUNT(request.id)')
            ->andWhere('request.status = :status')
            ->setParameter('status', PurchaseRequest::STATUS_PENDING);

        if ($actor->isFamilyParent() && $actor->getFamily() !== null) {
            $qb->andWhere('request.family = :family')->setParameter('family', $actor->getFamily());
        } else {
            $qb->andWhere('request.subject = :actor')->setParameter('actor', $actor);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return PurchaseRequest[] */
    public function findPendingCreatedBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('request')
            ->addSelect('subject', 'family')
            ->join('request.subject', 'subject')
            ->join('request.family', 'family')
            ->andWhere('request.status = :status')
            ->andWhere('request.createdAt < :before')
            ->setParameter('status', PurchaseRequest::STATUS_PENDING)
            ->setParameter('before', $before)
            ->orderBy('request.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
