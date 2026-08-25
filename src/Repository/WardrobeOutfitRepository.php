<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeOutfit> */
class WardrobeOutfitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeOutfit::class);
    }

    /** @return WardrobeOutfit[] */
    public function findRecentReacted(User $wardrobeOwner, int $limit = 100): array
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.wardrobeOwner = :wardrobeOwner')
            ->andWhere('outfit.reaction IS NOT NULL')
            ->setParameter('wardrobeOwner', $wardrobeOwner)
            ->orderBy('outfit.reactedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Сколько образов пользователь создал в окне [from, to) — бар квалификации реферальной награды. */
    public function countCreatedByUserBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('outfit')
            ->select('COUNT(outfit.id)')
            ->where('outfit.user = :user')
            ->andWhere('outfit.createdAt >= :from')
            ->andWhere('outfit.createdAt < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
