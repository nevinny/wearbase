<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandSourceUrl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandSourceUrl>
 */
class BrandSourceUrlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandSourceUrl::class);
    }

    /** URL с таким хешем уже в очереди у бренда? (дедуп при enqueue) */
    public function findOneByBrandUrlHash(Brand $brand, string $urlHash): ?BrandSourceUrl
    {
        return $this->findOneBy(['brand' => $brand, 'urlHash' => $urlHash]);
    }

    /** Сколько URL данного source_type уже у бренда (для caps на enqueue). */
    public function countByBrandType(Brand $brand, string $type): int
    {
        return $this->count(['brand' => $brand, 'sourceType' => $type]);
    }

    /**
     * Атомарно заклеймить пачку pending-URL своего шарда (MOD(brand_id,total)=shard).
     * SELECT ... FOR UPDATE SKIP LOCKED (MySQL 9) → UPDATE status=claimed в одной транзакции,
     * чтобы параллельные воркеры не дрались за одни строки.
     *
     * @return BrandSourceUrl[] заклейменные сущности (порядок tier ASC, relevance_score DESC)
     */
    public function claimPending(int $shard, int $total, int $batch): array
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        return $em->wrapInTransaction(function () use ($em, $conn, $shard, $total, $batch) {
            $ids = $conn->fetchFirstColumn(
                'SELECT id FROM brand_source_url
                  WHERE status = :pending AND MOD(brand_id, :total) = :shard
                  ORDER BY tier ASC, relevance_score DESC
                  LIMIT :batch
                  FOR UPDATE SKIP LOCKED',
                [
                    'pending' => BrandSourceUrl::STATUS_PENDING,
                    'total'   => $total,
                    'shard'   => $shard,
                    'batch'   => $batch,
                ],
                [
                    'pending' => \PDO::PARAM_STR,
                    'total'   => \PDO::PARAM_INT,
                    'shard'   => \PDO::PARAM_INT,
                    'batch'   => \PDO::PARAM_INT,
                ],
            );

            if ($ids === []) {
                return [];
            }

            $conn->executeStatement(
                'UPDATE brand_source_url
                    SET status = :claimed, claimed_at = NOW()
                  WHERE id IN (:ids)',
                ['claimed' => BrandSourceUrl::STATUS_CLAIMED, 'ids' => $ids],
                ['claimed' => \PDO::PARAM_STR, 'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );

            // Заклейменные строки изменены в БД вне UoW — освежаем перед возвратом.
            $rows = $this->createQueryBuilder('u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('u.tier', 'ASC')
                ->addOrderBy('u.relevanceScore', 'DESC')
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $em->refresh($row);
            }

            return $rows;
        });
    }

    /**
     * Вернуть протухшие claimed-URL (claimed дольше $minutes) обратно в pending.
     * Ловит воркеров, упавших после claim, но до fetch.
     *
     * @return int сколько строк реклеймлено
     */
    public function reclaimStale(int $minutes): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE brand_source_url
                SET status = :pending, claimed_at = NULL
              WHERE status = :claimed
                AND claimed_at IS NOT NULL
                AND claimed_at < (NOW() - INTERVAL :minutes MINUTE)',
            [
                'pending' => BrandSourceUrl::STATUS_PENDING,
                'claimed' => BrandSourceUrl::STATUS_CLAIMED,
                'minutes' => $minutes,
            ],
            [
                'pending' => \PDO::PARAM_STR,
                'claimed' => \PDO::PARAM_STR,
                'minutes' => \PDO::PARAM_INT,
            ],
        );
    }
}
