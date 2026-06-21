<?php

namespace App\Repository;

use App\Entity\CityHub;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CityHub>
 */
class CityHubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CityHub::class);
    }

    public function findActiveBySlug(string $slug): ?CityHub
    {
        return $this->findOneBy(['slug' => $slug, 'isActive' => true]);
    }
}
