<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeCircle;
use App\Entity\User;
use App\Entity\WardrobeOutfitShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeOutfitShare> */
class WardrobeOutfitShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeOutfitShare::class);
    }

    /** Токен ищется напрямую по уникальному индексу; viewability (статус/TTL) проверяется на сущности. */
    public function findByToken(string $token): ?WardrobeOutfitShare
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Все ГОСТЕВЫЕ ссылки луков гардероба member (для блока «Поделиться» в ЛК):
     * сначала ожидающие подтверждения родителя, затем свежие. Кружковые гранты
     * (circle_id NOT NULL) не показываются — у них нет гостевого токена (§2).
     *
     * @return list<WardrobeOutfitShare>
     */
    public function findForWardrobeOwner(User $wardrobeOwner): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.outfit', 'o')
            ->where('o.wardrobeOwner = :owner')
            ->andWhere('s.circle IS NULL')
            ->setParameter('owner', $wardrobeOwner)
            ->orderBy('CASE WHEN s.status = :pending THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('s.id', 'DESC')
            ->setParameter('pending', WardrobeOutfitShare::STATUS_PENDING_PARENT)
            ->getQuery()
            ->getResult();
    }

    /**
     * Лента кружка (§2): только луки, явно расшаренные в этот кружок; живых
     * грантов — active и неистёкших. Порядок: свежие подтверждения сверху.
     *
     * @return list<WardrobeOutfitShare>
     */
    public function findActiveForCircle(WardrobeCircle $circle): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.circle = :circle')
            ->andWhere('s.status = :active')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('circle', $circle)
            ->setParameter('active', WardrobeOutfitShare::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.grantedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Живые кружковые гранты луков автора (для массового revoke при отзыве
     * родительского согласия, §3.1 спеки кружков).
     *
     * @return list<WardrobeOutfitShare>
     */
    public function findLiveForAuthor(User $author): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.outfit', 'o')
            ->where('s.circle IS NOT NULL')
            ->andWhere('o.user = :author OR o.wardrobeOwner = :author')
            ->andWhere('s.status != :revoked')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('author', $author)
            ->setParameter('revoked', WardrobeOutfitShare::STATUS_REVOKED)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
