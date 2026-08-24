<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebPushSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WebPushSubscription> */
class WebPushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebPushSubscription::class);
    }

    public function findByEndpoint(string $endpoint): ?WebPushSubscription
    {
        return $this->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
    }

    /** @return WebPushSubscription[] */
    public function findActiveForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'revokedAt' => null]);
    }

    public function deleteRevokedBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('s')->delete()
            ->andWhere('s.revokedAt IS NOT NULL')
            ->andWhere('s.revokedAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
