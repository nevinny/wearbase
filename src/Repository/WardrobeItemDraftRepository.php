<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeItemDraft>
 */
class WardrobeItemDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeItemDraft::class);
    }

    /**
     * @return WardrobeItemDraft[]
     */
    public function findPending(int $limit, ?string $batchId = null): array
    {
        $query = $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->setParameter('status', WardrobeItemDraft::STATUS_PENDING)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit);
        if ($batchId !== null) {
            $query->andWhere('d.batchId = :batchId')->setParameter('batchId', $batchId);
        }

        return $query->getQuery()
            ->getResult();
    }

    /**
     * @return WardrobeItemDraft[]
     */
    public function findByBatch(User $user, string $batchId): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.batchId = :batchId')
            ->setParameter('user', $user)
            ->setParameter('batchId', $batchId)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total:int,pending:int,recognized:int,failed:int}
     */
    public function countsByBatch(User $user, string $batchId): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.status AS status', 'COUNT(d.id) AS cnt')
            ->andWhere('d.user = :user')
            ->andWhere('d.batchId = :batchId')
            ->andWhere('d.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('batchId', $batchId)
            ->setParameter('terminal', [WardrobeItemDraft::STATUS_ACCEPTED, WardrobeItemDraft::STATUS_REJECTED])
            ->groupBy('d.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['total' => 0, 'pending' => 0, 'recognized' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            $counts['total'] += $cnt;
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = $cnt;
            }
        }

        return $counts;
    }
}
