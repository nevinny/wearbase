<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemLifecycleEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeItemLifecycleEvent> */
class WardrobeItemLifecycleEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeItemLifecycleEvent::class);
    }

    /** @return WardrobeItemLifecycleEvent[] */
    public function findForItem(WardrobeItem $item): array
    {
        return $this->findBy(['item' => $item], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
