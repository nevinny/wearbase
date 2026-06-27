<?php

namespace App\Repository;

use App\Entity\BrandOutboundClick;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandOutboundClick>
 */
class BrandOutboundClickRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandOutboundClick::class);
    }

    /**
     * Топ брендов по числу исходящих кликов за последние N дней.
     *
     * @return list<array{brand_id: int, slug: string, title: string, clicks: int}>
     */
    public function topBrands(int $days = 30, int $limit = 50): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.brand_id, b.slug, b.title, COUNT(*) AS clicks
               FROM brand_outbound_click c
               JOIN brand b ON b.id = c.brand_id
              WHERE c.created_at >= NOW() - INTERVAL :days DAY
              GROUP BY c.brand_id, b.slug, b.title
              ORDER BY clicks DESC
              LIMIT :limit',
            ['days' => $days, 'limit' => $limit],
            ['days' => \PDO::PARAM_INT, 'limit' => \PDO::PARAM_INT],
        );
    }

    /** Сколько исходящих кликов у бренда за последние N дней. */
    public function countForBrand(int $brandId, int $days = 30): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM brand_outbound_click
              WHERE brand_id = :id AND created_at >= NOW() - INTERVAL :days DAY',
            ['id' => $brandId, 'days' => $days],
            ['id' => \PDO::PARAM_INT, 'days' => \PDO::PARAM_INT],
        );
    }
}
