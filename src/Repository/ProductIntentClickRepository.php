<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\ProductIntentClick;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductIntentClick>
 */
class ProductIntentClickRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductIntentClick::class);
    }

    /**
     * Сколько сигналов «Хочу купить» у бренда за всё время. DQL (не native SQL с
     * NOW()/INTERVAL) — портируется на тестовый SQLite.
     */
    public function countForBrand(Brand $brand): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.brand = :brand')
            ->setParameter('brand', $brand)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Топ брендов по числу сигналов «Хочу купить» за последние N дней (для админ-дашборда
     * кликов). Native SQL как в BrandOutboundClickRepository::topBrands — только для
     * /admin/clicks, тестами не покрыто.
     *
     * @return list<array{brand_id: int, slug: string, title: string, clicks: int}>
     */
    public function topBrands(int $days = 30, int $limit = 10): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.brand_id, b.slug, b.title, COUNT(*) AS clicks
               FROM product_intent_click c
               JOIN brand b ON b.id = c.brand_id
              WHERE c.created_at >= NOW() - INTERVAL :days DAY
              GROUP BY c.brand_id, b.slug, b.title
              ORDER BY clicks DESC
              LIMIT :limit',
            ['days' => $days, 'limit' => $limit],
            ['days' => \PDO::PARAM_INT, 'limit' => \PDO::PARAM_INT],
        );
    }
}
