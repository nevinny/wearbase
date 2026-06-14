<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandContentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandContentRevision>
 */
class BrandContentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandContentRevision::class);
    }

    /** Активная (живая) ревизия бренда — зеркалит brand.*. */
    public function findActive(Brand $brand): ?BrandContentRevision
    {
        return $this->findOneBy(['brand' => $brand, 'isActive' => true]);
    }

    public function hasAny(Brand $brand): bool
    {
        return null !== $this->findOneBy(['brand' => $brand]);
    }

    /**
     * Эксперименты к оценке: pending + окно замера истекло.
     * @return BrandContentRevision[]
     */
    public function findDueForEvaluation(\DateTimeInterface $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.verdict = :pending')
            ->andWhere('r.measureAfter IS NOT NULL AND r.measureAfter <= :now')
            ->setParameter('pending', BrandContentRevision::VERDICT_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.measureAfter', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Лучшая прошлая ревизия для отката: предпочитаем явные win, затем последнюю
     * не-активную (что была до текущей).
     */
    public function findRollbackTarget(Brand $brand, int $excludeId): ?BrandContentRevision
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.brand = :brand')
            ->andWhere('r.id != :excl')
            ->setParameter('brand', $brand)
            ->setParameter('excl', $excludeId)
            ->setMaxResults(1);

        // сначала ищем подтверждённый win
        $win = (clone $qb)
            ->andWhere('r.verdict = :win')
            ->setParameter('win', BrandContentRevision::VERDICT_WIN)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getOneOrNullResult();
        if ($win !== null) {
            return $win;
        }

        // иначе — самая свежая прошлая ревизия
        return $qb->orderBy('r.createdAt', 'DESC')->getQuery()->getOneOrNullResult();
    }
}
