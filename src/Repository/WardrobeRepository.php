<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Wardrobe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wardrobe>
 */
class WardrobeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wardrobe::class);
    }

    public function findDefaultForOwner(User $owner): ?Wardrobe
    {
        return $this->findOneBy([
            'owner' => $owner,
            'isDefault' => true,
            'deletedAt' => null,
        ]);
    }
}
