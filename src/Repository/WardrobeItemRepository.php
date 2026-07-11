<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeItem>
 */
class WardrobeItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeItem::class);
    }

    /**
     * @return WardrobeItem[]
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('w.itemNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveOneForUser(int $id, User $user): ?WardrobeItem
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Статистика по категориям: [['category' => ..., 'cnt' => ..., 'total' => ...], ...]
     *
     * @return array<int, array{category: string, cnt: int, total: string}>
     */
    public function getStats(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->select('w.category AS category', 'COUNT(w.id) AS cnt', 'COALESCE(SUM(w.price), 0) AS total')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('w.category')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Следующий сквозной номер вещи (по всем записям юзера, включая soft-deleted).
     */
    public function nextItemNo(User $user): int
    {
        $max = $this->createQueryBuilder('w')
            ->select('COALESCE(MAX(w.itemNo), 0)')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }

    /**
     * @return string[]
     */
    public function distinctCategories(User $user): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('DISTINCT w.category')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }
}
