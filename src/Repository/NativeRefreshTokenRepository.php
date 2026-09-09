<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NativeRefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NativeRefreshToken> */
final class NativeRefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, NativeRefreshToken::class); }

    public function findForUpdate(string $hash): ?NativeRefreshToken
    {
        return $this->createQueryBuilder('t')->andWhere('t.tokenHash = :hash')->setParameter('hash', $hash)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('t')->delete()->andWhere('t.expiresAt <= :now')
            ->setParameter('now', $now)->getQuery()->execute();
    }
}
