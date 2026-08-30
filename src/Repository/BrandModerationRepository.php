<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandModeration>
 */
class BrandModerationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandModeration::class);
    }

    public function findOneByBrand(Brand $brand): ?BrandModeration
    {
        return $this->findOneBy(['brand' => $brand]);
    }

    /** Очередь на анализ, старейшие первыми. */
    public function findQueued(int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->setParameter('status', BrandModeration::STATUS_QUEUED)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * `reviewed` без решения дольше $cutoff — админу пора напомнить. Троттлинг тем же
     * $cutoff: не дёргаем, если уже напоминали недавно (app:moderation:timeouts).
     *
     * @return BrandModeration[]
     */
    public function findOverdueReviewed(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.decidedAt IS NULL')
            ->andWhere('m.analyzedAt < :cutoff')
            ->andWhere('(m.remindedAt IS NULL OR m.remindedAt < :cutoff)')
            ->setParameter('status', BrandModeration::STATUS_REVIEWED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.analyzedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** `queued` дольше $cutoff — очередь либо не трогается анализатором, либо застряла на ручной. @return BrandModeration[] */
    public function findStalledQueued(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.createdAt < :cutoff')
            ->setParameter('status', BrandModeration::STATUS_QUEUED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** `changes_requested` без ответа владельца дольше $cutoff — архивируем (см. STATUS_ARCHIVED). @return BrandModeration[] */
    public function findStaleChangesRequested(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.decidedAt < :cutoff')
            ->setParameter('status', BrandModeration::STATUS_CHANGES_REQUESTED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.decidedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
