<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NativeDeviceSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NativeDeviceSession> */
final class NativeDeviceSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, NativeDeviceSession::class); }

    public function findValidAccess(string $hash, \DateTimeImmutable $now): ?NativeDeviceSession
    {
        return $this->createQueryBuilder('s')->andWhere('s.accessHash = :hash')->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.accessExpiresAt > :now')->setParameter('hash', $hash)->setParameter('now', $now)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return NativeDeviceSession[] */
    public function findActiveForDevice(User $user, string $deviceHash): array
    {
        return $this->findBy(['user' => $user, 'deviceHash' => $deviceHash, 'revokedAt' => null]);
    }

    /** @return NativeDeviceSession[] */
    public function findActiveForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'revokedAt' => null]);
    }
}
