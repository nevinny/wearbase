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

    /** @return NativeDeviceSession[] */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function findOwnedByPublicId(User $user, string $publicId): ?NativeDeviceSession
    {
        return $this->findOneBy(['user' => $user, 'publicId' => $publicId]);
    }

    public function touchLastUsed(NativeDeviceSession $session, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->getConnection()->update('native_device_session',
            ['last_used_at' => $at->format('Y-m-d H:i:s')],
            ['id' => $session->getId()],
        );
        $session->touch($at);
    }

    public function deleteRevokedBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('s')->delete()->andWhere('s.revokedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)->getQuery()->execute();
    }

    public function deleteExpiredWithoutRefresh(\DateTimeImmutable $now): int
    {
        return $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\NativeDeviceSession s WHERE s.revokedAt IS NULL AND s.accessExpiresAt <= :now AND NOT EXISTS (SELECT t.id FROM App\Entity\NativeRefreshToken t WHERE IDENTITY(t.session) = s.id AND t.expiresAt > :now)',
        )->setParameter('now', $now)->execute();
    }
}
