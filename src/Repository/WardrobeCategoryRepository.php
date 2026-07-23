<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeCategory>
 */
class WardrobeCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeCategory::class);
    }

    /**
     * @return WardrobeCategory[]
     */
    public function findActiveTree(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }
}
