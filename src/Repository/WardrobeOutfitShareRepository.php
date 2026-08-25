<?php

declare(strict_types=1);

namespace App\Repository;

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
     * Все ссылки луков гардероба member (для блока «Поделиться» в ЛК):
     * сначала ожидающие подтверждения родителя, затем свежие.
     *
     * @return list<WardrobeOutfitShare>
     */
    public function findForWardrobeOwner(User $wardrobeOwner): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.outfit', 'o')
            ->where('o.wardrobeOwner = :owner')
            ->setParameter('owner', $wardrobeOwner)
            ->orderBy('CASE WHEN s.status = :pending THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('s.id', 'DESC')
            ->setParameter('pending', WardrobeOutfitShare::STATUS_PENDING_PARENT)
            ->getQuery()
            ->getResult();
    }
}
