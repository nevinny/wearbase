<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandOutreach;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandOutreach>
 */
class BrandOutreachRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandOutreach::class);
    }

    /** Suppression ПО EMAIL (один владелец = несколько брендов): отписка/hard-bounce любого его бренда. */
    public function isSuppressed(string $email): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM brand_outreach
              WHERE email = :email AND (unsubscribed_at IS NOT NULL OR bounced_at IS NOT NULL))',
            ['email' => $email],
        );
    }

    public function findByBrand(Brand $brand): ?BrandOutreach
    {
        return $this->findOneBy(['brand' => $brand]);
    }

    /**
     * Ретраи: попытка была, не доставлено, не суппрессировано, ≤maxAttempts, backoff.
     * @return BrandOutreach[]
     */
    public function findRetryable(int $limit, int $maxAttempts = 3, int $backoffHours = 6): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT o.id FROM brand_outreach o
             JOIN brand b ON b.id = o.brand_id
             WHERE o.sent_at IS NULL
               AND o.attempts >= 1 AND o.attempts < :max
               AND o.unsubscribed_at IS NULL AND o.bounced_at IS NULL
               AND o.updated_at < (NOW() - INTERVAL :backoff HOUR)
               AND b.status = 'active'
             ORDER BY o.updated_at ASC
             LIMIT " . max(1, $limit),
            ['max' => $maxAttempts, 'backoff' => $backoffHours],
        );

        return $ids === [] ? [] : $this->findBy(['id' => $ids]);
    }
}
