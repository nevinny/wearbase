<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalNotificationOutbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExternalNotificationOutbox> */
class ExternalNotificationOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalNotificationOutbox::class);
    }

    public function claimNext(\DateTimeImmutable $now): ?ExternalNotificationOutbox
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($now): ?ExternalNotificationOutbox {
            $message = $this->createQueryBuilder('o')
                ->andWhere('(o.status = :status OR (o.status = :processing AND o.lockedAt < :expired))')
                ->andWhere('o.availableAt <= :now')
                ->setParameter('status', ExternalNotificationOutbox::STATUS_PENDING)
                ->setParameter('processing', ExternalNotificationOutbox::STATUS_PROCESSING)
                ->setParameter('expired', $now->modify('-10 minutes'))
                ->setParameter('now', $now)
                ->orderBy('o.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            $message?->claim($now);

            return $message;
        });
    }
}
