<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FittingFeedback;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FittingFeedback> */
class FittingFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FittingFeedback::class);
    }

    /** @return FittingFeedback[] */
    public function findForSubject(User $subject): array
    {
        return $this->createQueryBuilder('feedback')
            ->join('feedback.item', 'item')
            ->join('item.purchaseRequest', 'request')
            ->andWhere('request.subject = :subject')
            ->setParameter('subject', $subject)
            ->orderBy('feedback.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}
