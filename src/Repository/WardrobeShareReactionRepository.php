<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeShareReaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeShareReaction> */
class WardrobeShareReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeShareReaction::class);
    }

    /**
     * Агрегат ленты одним GROUP BY запросом (docs/ratings-spec.md §5, инвариант №3
     * circles-spec §4): никаких денормализованных счётчиков на share.
     *
     * @param list<int> $shareIds
     *
     * @return array<int, int> share_id → число огней (отсутствующие ключи = 0)
     */
    public function countsByShareIds(array $shareIds): array
    {
        if ($shareIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.share) AS shareId', 'COUNT(r.id) AS fires')
            ->where('r.share IN (:ids)')
            ->setParameter('ids', $shareIds)
            ->groupBy('shareId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['shareId']] = (int) $row['fires'];
        }

        return $counts;
    }

    /** Текущая сумма огней одного share (идемпотентный ответ react()). */
    public function countForShare(int $shareId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.share = :id')
            ->setParameter('id', $shareId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
