<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeTransfer>
 */
class WardrobeTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeTransfer::class);
    }

    /**
     * История передач вещи (новые сверху).
     *
     * @return WardrobeTransfer[]
     */
    public function findForItem(WardrobeItem $item): array
    {
        return $this->findBy(['item' => $item], ['transferredAt' => 'DESC', 'id' => 'DESC']);
    }
}
