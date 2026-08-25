<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReferralEvent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReferralEvent> */
class ReferralEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralEvent::class);
    }

    /** Скорость приглашений: сколько событий inviter создал с указанного момента (антифрод §3). */
    public function countForInviterSince(User $inviter, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.inviter = :inviter')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('inviter', $inviter)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ReferralEvent> все события inviter (для кластер-флага «приглашённые без действия»). */
    public function findAllForInviter(User $inviter): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.inviter = :inviter')
            ->setParameter('inviter', $inviter)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
