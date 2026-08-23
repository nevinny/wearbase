<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\FamilyInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyInvite>
 */
class FamilyInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyInvite::class);
    }

    /**
     * Неиспользованные инвайты семьи (новые сверху).
     *
     * @return FamilyInvite[]
     */
    public function findPendingForFamily(Family $family): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.family = :family')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('family', $family)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
