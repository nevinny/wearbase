<?php

namespace App\Repository;

use App\Entity\AioRemediation;
use App\Entity\Brand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AioRemediation>
 */
class AioRemediationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AioRemediation::class);
    }

    /** Уже есть pending-кандидат для этого бренда+вида — обновляем его вместо дубля. */
    public function findOnePending(Brand $brand, string $kind): ?AioRemediation
    {
        return $this->findOneBy([
            'brand'  => $brand,
            'kind'   => $kind,
            'status' => AioRemediation::STATUS_PENDING,
        ]);
    }
}
