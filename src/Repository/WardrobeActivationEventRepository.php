<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeActivationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeActivationEvent> */
class WardrobeActivationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeActivationEvent::class); }

    /** @param array<string, bool|string> $metadata */
    public function recordOnce(User $subject, string $eventType, string $dedupKey, array $metadata): bool
    {
        try {
            $this->getEntityManager()->getConnection()->insert('wardrobe_activation_event', [
                'profile_subject_id' => $subject->getId(),
                'event_type' => $eventType,
                'dedup_key' => $dedupKey,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** @return list<array{profileSubjectId:int,eventType:string,metadata:array<string,bool|string>,occurredAt:string}> */
    public function findReportRows(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT profile_subject_id, event_type, metadata, occurred_at FROM wardrobe_activation_event WHERE occurred_at >= :from AND occurred_at < :to ORDER BY occurred_at ASC',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $row): array => [
            'profileSubjectId' => (int) $row['profile_subject_id'],
            'eventType' => (string) $row['event_type'],
            'metadata' => json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR),
            'occurredAt' => (string) $row['occurred_at'],
        ], $rows);
    }
}
