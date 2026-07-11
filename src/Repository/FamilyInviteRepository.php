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
        return $this->findBy(['family' => $family, 'acceptedAt' => null], ['createdAt' => 'DESC']);
    }
}
