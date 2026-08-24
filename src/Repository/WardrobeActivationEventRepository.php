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

    /** @param array{actorKind:string,entryPoint:string} $metadata */
    public function recordOnce(User $subject, string $eventType, array $metadata): bool
    {
        try {
            $this->getEntityManager()->getConnection()->insert('wardrobe_activation_event', [
                'profile_subject_id' => $subject->getId(),
                'event_type' => $eventType,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
