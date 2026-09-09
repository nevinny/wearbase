<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReferralRewardGrant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReferralRewardGrant> */
class ReferralRewardGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralRewardGrant::class);
    }

    public function existsByIdempotencyKey(string $key): bool
    {
        return $this->count(['idempotencyKey' => $key]) > 0;
    }

    /** @return list<ReferralRewardGrant> */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->andWhere('g.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ReferralRewardGrant::STATUS_ACTIVE)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Сумма активных дневных бампов AI-квоты пользователя (потолок ≤+30 считает сервис экономики). */
    public function sumActiveDailyBump(User $user): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COALESCE(SUM(g.amount), 0)')
            ->where('g.user = :user')
            ->andWhere('g.kind = :kind')
            ->andWhere('g.status = :status')
            ->setParameter('user', $user)
            ->setParameter('kind', ReferralRewardGrant::KIND_AI_QUOTA_BUMP)
            ->setParameter('status', ReferralRewardGrant::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Оплаченные квалификации приглашающей за календарный месяц (для месячного капа):
     * считаем все выданные inviter-bump'ы кроме отозванных — expired тоже был оплатой.
     */
    public function countInviterBumpsBetween(User $inviter, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.user = :inviter')
            ->andWhere('g.role = :role')
            ->andWhere('g.kind = :kind')
            ->andWhere('g.reason = :reason')
            ->andWhere('g.status != :revoked')
            ->andWhere('g.grantedAt >= :from')
            ->andWhere('g.grantedAt < :to')
            ->setParameter('inviter', $inviter)
            ->setParameter('role', ReferralRewardGrant::ROLE_INVITER)
            ->setParameter('kind', ReferralRewardGrant::KIND_AI_QUOTA_BUMP)
            ->setParameter('reason', 'bump')
            ->setParameter('revoked', ReferralRewardGrant::STATUS_REVOKED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Активные гранты с наступившей датой истечения — для app:referral:expire-grants. */
    public function expireDue(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('g')
            ->update()
            ->set('g.status', ':expired')
            ->where('g.status = :active')
            ->andWhere('g.expiresAt IS NOT NULL')
            ->andWhere('g.expiresAt <= :now')
            ->setParameter('expired', ReferralRewardGrant::STATUS_EXPIRED)
            ->setParameter('active', ReferralRewardGrant::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
