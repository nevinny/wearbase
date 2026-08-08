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

    /**
     * @param WardrobeItem[] $items
     * @return array<int, WardrobeTransfer[]>
     */
    public function findGroupedForItems(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $transfers = $this->createQueryBuilder('transfer')
            ->addSelect('item', 'fromUser', 'toUser', 'actor')
            ->join('transfer.item', 'item')
            ->join('transfer.fromUser', 'fromUser')
            ->join('transfer.toUser', 'toUser')
            ->join('transfer.actor', 'actor')
            ->andWhere('transfer.item IN (:items)')
            ->setParameter('items', $items)
            ->orderBy('transfer.transferredAt', 'ASC')
            ->addOrderBy('transfer.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($transfers as $transfer) {
            $grouped[$transfer->getItem()->getId()][] = $transfer;
        }

        return $grouped;
    }
}
