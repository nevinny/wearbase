<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandDatapoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandDatapoint>
 */
class BrandDatapointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandDatapoint::class);
    }

    /** Ленивое создание: строки нет = enrichment/active по умолчанию. */
    public function getOrCreate(Brand $brand, string $targetType, ?int $targetId, string $field): BrandDatapoint
    {
        $dp = $this->findOneBy([
            'brand'      => $brand,
            'targetType' => $targetType,
            'targetId'   => $targetId,
            'field'      => $field,
        ]);

        if ($dp === null) {
            $dp = (new BrandDatapoint())
                ->setBrand($brand)
                ->setTargetType($targetType)
                ->setTargetId($targetId)
                ->setField($field);
            $this->getEntityManager()->persist($dp);
        }

        return $dp;
    }

    /**
     * Скрытые точки бренда — для фильтрации на странице.
     * @return BrandDatapoint[]
     */
    public function findHiddenByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand, 'state' => BrandDatapoint::STATE_HIDDEN]);
    }

    /**
     * Очередь ре-обогащения для агента (GET /api/v1/revalidation-queue).
     * @return BrandDatapoint[]
     */
    public function findQueuedForRevalidation(int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.queuedRevalidateAt IS NOT NULL')
            ->andWhere('d.revalidatedAt IS NULL')
            ->orderBy('d.queuedRevalidateAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Точка с provenance=owner? (owner-guard в BrandIngestService) */
    public function isOwnerProvenance(Brand $brand, string $targetType, ?int $targetId, string $field): bool
    {
        return $this->count([
            'brand'      => $brand,
            'targetType' => $targetType,
            'targetId'   => $targetId,
            'field'      => $field,
            'provenance' => BrandDatapoint::PROV_OWNER,
        ]) > 0;
    }
}
