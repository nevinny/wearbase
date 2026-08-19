<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeManualOutfit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeManualOutfit> */
final class WardrobeManualOutfitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeManualOutfit::class); }

    /** @return WardrobeManualOutfit[] */
    public function findActiveForOwner(User $owner): array
    {
        return $this->createQueryBuilder('outfit')
            ->addSelect('items')->leftJoin('outfit.items', 'items')
            ->andWhere('outfit.wardrobeOwner = :owner')->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('owner', $owner)->orderBy('outfit.updatedAt', 'DESC')->addOrderBy('outfit.createdAt', 'DESC')
            ->getQuery()->getResult();
    }
}
