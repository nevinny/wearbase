<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeCircleInvite> */
class WardrobeCircleInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeCircleInvite::class);
    }

    /** Токен ищется напрямую по уникальному индексу; usable проверяется на сущности. */
    public function findByToken(string $token): ?WardrobeCircleInvite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return list<WardrobeCircleInvite> активные ссылки кружка (блок «Пригласить»). */
    public function findActiveForCircle(WardrobeCircle $circle): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.circle = :circle')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('circle', $circle)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
