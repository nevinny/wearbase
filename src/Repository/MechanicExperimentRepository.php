<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MechanicExperiment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MechanicExperiment>
 */
class MechanicExperimentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MechanicExperiment::class);
    }

    public function findByCode(string $code): ?MechanicExperiment
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Все уже заведённые коды (идемпотентность propose — не предлагать дважды). */
    public function existingCodes(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.code')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'code');
    }

    /**
     * Running-эксперименты с истёкшим окном (ends_at ≤ now) — к оценке.
     * @return MechanicExperiment[]
     */
    public function findRunningDue(\DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.status = :status')
            ->andWhere('e.endsAt IS NOT NULL AND e.endsAt <= :now')
            ->setParameter('status', MechanicExperiment::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->orderBy('e.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
