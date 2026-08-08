<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseRequest;
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
}
