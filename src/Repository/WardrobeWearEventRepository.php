<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeWearEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeWearEvent> */
final class WardrobeWearEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeWearEvent::class); }

    public function findConfirmedForOutfitDay(User $subject, WardrobeOutfit $outfit, \DateTimeImmutable $day): ?WardrobeWearEvent
    {
        return $this->findOneBy([
            'profileSubject' => $subject,
            'sourceOutfit' => $outfit,
            'wornOn' => $day->setTime(0, 0),
            'type' => WardrobeWearEvent::TYPE_WORN,
            'status' => WardrobeWearEvent::STATUS_CONFIRMED,
        ]);
    }

    /** @return array<int, array{itemId:int, wearCount:int, lastWorn:string, price:?string}> */
    public function itemWearStats(User $subject): array
    {
        $rows = $this->createQueryBuilder('event')
            ->select('IDENTITY(eventItem.item) AS itemId', 'COUNT(event.id) AS wearCount', 'MAX(event.wornOn) AS lastWorn', 'item.price AS price')
            ->join('event.items', 'eventItem')
            ->join('eventItem.item', 'item')
            ->andWhere('event.profileSubject = :subject')
            ->andWhere('event.status = :status')
            ->andWhere('event.type = :type')
            ->andWhere('eventItem.confirmed = true')
            ->setParameter('subject', $subject)
            ->setParameter('status', WardrobeWearEvent::STATUS_CONFIRMED)
            ->setParameter('type', WardrobeWearEvent::TYPE_WORN)
            ->groupBy('eventItem.item', 'item.price')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => [
            'itemId' => (int) $row['itemId'],
            'wearCount' => (int) $row['wearCount'],
            'lastWorn' => (string) $row['lastWorn'],
            'price' => $row['price'] !== null ? (string) $row['price'] : null,
        ], $rows);
    }

    public function hasRepeatedItem(User $subject): bool
    {
        return $this->createQueryBuilder('event')
            ->select('COUNT(event.id)')
            ->join('event.items', 'eventItem')
            ->andWhere('event.profileSubject = :subject')
            ->andWhere('event.status = :status')
            ->andWhere('event.type = :type')
            ->andWhere('eventItem.confirmed = true')
            ->setParameter('subject', $subject)
            ->setParameter('status', WardrobeWearEvent::STATUS_CONFIRMED)
            ->setParameter('type', WardrobeWearEvent::TYPE_WORN)
            ->groupBy('eventItem.item')
            ->having('COUNT(event.id) >= 2')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult() !== null;
    }

    /** @return WardrobeWearEvent[] */
    public function findRecentConfirmed(User $subject, int $limit = 20): array
    {
        return $this->createQueryBuilder('event')
            ->addSelect('eventItem', 'item')
            ->join('event.items', 'eventItem')
            ->join('eventItem.item', 'item')
            ->andWhere('event.profileSubject = :subject')
            ->andWhere('event.status = :status')
            ->setParameter('subject', $subject)
            ->setParameter('status', WardrobeWearEvent::STATUS_CONFIRMED)
            ->orderBy('event.wornOn', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
