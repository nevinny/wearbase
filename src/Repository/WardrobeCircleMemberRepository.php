<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeCircleMember> */
class WardrobeCircleMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeCircleMember::class);
    }

    /**
     * Живое членство actor'а в кружке (active) — вызывается на каждый запрос к ленте,
     * выход/кик лишают доступа мгновенно (§3.3). Dissolved-кружок ленты не отдаёт.
     */
    public function findActive(User $user, WardrobeCircle $circle): ?WardrobeCircleMember
    {
        $member = $this->findOneBy(['circle' => $circle, 'user' => $user]);

        return $member !== null && $member->isActive() && !$circle->isDissolved() ? $member : null;
    }

    /** Число слотов капа, занятых пользователем (active + pending_parent ≤ 5 кружков). */
    public function countOccupiedCircles(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.circle)')
            ->where('m.user = :user')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', WardrobeCircleMember::CAP_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Число занятых слотов участников кружка (жёсткий кап MEMBER_CAP на вставке). */
    public function countOccupiedMembers(WardrobeCircle $circle): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.circle = :circle')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('circle', $circle)
            ->setParameter('statuses', WardrobeCircleMember::CAP_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<WardrobeCircleMember> участники карточки: сначала owner, затем по дате входа. */
    public function findListedForCircle(WardrobeCircle $circle): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.circle = :circle')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('circle', $circle)
            ->setParameter('statuses', WardrobeCircleMember::CAP_STATUSES)
            ->orderBy('CASE WHEN m.role = :owner THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setParameter('owner', WardrobeCircleMember::ROLE_OWNER)
            ->getQuery()
            ->getResult();
    }

    /** @return list<WardrobeCircleMember> pending-карточки родителя по всем кружкам. */
    public function findPendingParentFor(User $parent): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.user', 'u')
            ->where('m.status = :pending')
            ->andWhere('u.family = :family')
            ->andWhere('u.familyRole = :child')
            ->setParameter('pending', WardrobeCircleMember::STATUS_PENDING_PARENT)
            ->setParameter('family', $parent->getFamily())
            ->setParameter('child', User::FAMILY_ROLE_CHILD)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Членство пользователя в любом живом кружке из списка (медиа-гейт ленты). */
    public function findActiveInAny(User $user, array $circles): bool
    {
        if ($circles === []) {
            return false;
        }

        return (bool) $this->createQueryBuilder('m')
            ->select('1')
            ->where('m.user = :user')
            ->andWhere('m.status = :active')
            ->andWhere('m.circle IN (:circles)')
            ->setParameter('user', $user)
            ->setParameter('active', WardrobeCircleMember::STATUS_ACTIVE)
            ->setParameter('circles', $circles)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
