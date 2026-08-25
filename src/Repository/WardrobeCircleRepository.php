<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeCircle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeCircle> */
class WardrobeCircleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeCircle::class);
    }

    /** @return list<WardrobeCircle> живые (не расформированные) кружки пользователя. */
    public function findAlive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.dissolvedAt IS NULL')
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
