<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OfferAcceptance;
use App\Entity\OfferDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OfferAcceptance>
 */
class OfferAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OfferAcceptance::class);
    }

    /** Акцептовал ли пользователь конкретную редакцию документа. */
    public function hasAccepted(User $user, OfferDocument $document): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.user = :user')
            ->andWhere('a.offerDocument = :document')
            ->setParameter('user', $user)
            ->setParameter('document', $document)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
