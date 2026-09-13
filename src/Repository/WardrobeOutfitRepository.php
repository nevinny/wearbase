<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeOutfit> */
class WardrobeOutfitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeOutfit::class);
    }

    /** @return WardrobeOutfit[] */
    public function findRecentReacted(User $wardrobeOwner, int $limit = 100): array
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.wardrobeOwner = :wardrobeOwner')
            ->andWhere('outfit.reaction IS NOT NULL')
            ->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('wardrobeOwner', $wardrobeOwner)
            ->orderBy('outfit.reactedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Витрина «Образы на утро»: результат ночного пакетного конвейера (occasion
     * задан) за конкретные сутки владельца. Интерактивные образы (occasion=null)
     * сюда не попадают — у них своя лента через findRecentReacted().
     *
     * @return WardrobeOutfit[]
     */
    public function findDailyForOwner(User $wardrobeOwner, \DateTimeImmutable $dayStart): array
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.wardrobeOwner = :owner')
            ->andWhere('outfit.occasion IS NOT NULL')
            ->andWhere('outfit.deletedAt IS NULL')
            ->andWhere('outfit.createdAt >= :from')
            ->andWhere('outfit.createdAt < :to')
            ->setParameter('owner', $wardrobeOwner)
            ->setParameter('from', $dayStart)
            ->setParameter('to', $dayStart->modify('+1 day'))
            ->orderBy('outfit.occasion', 'ASC')
            ->addOrderBy('outfit.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Живой (не мягко удалённый) образ владельца по id — единая точка чтения для
     * реакций/обучения. Владение определяется wardrobeOwner (а не user), т.к.
     * ночной пакетный конвейер и семейный сценарий (родитель управляет ребёнком)
     * ставят user/actor иначе; авторизация actor'а на управление wardrobeOwner
     * проверяется выше по стеку (FamilyService::resolveMember/canManage).
     */
    public function findActiveForOwner(int $id, User $wardrobeOwner): ?WardrobeOutfit
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.id = :id')
            ->andWhere('outfit.wardrobeOwner = :owner')
            ->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('owner', $wardrobeOwner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Как findActiveForOwner(), но с пессимистичной блокировкой строки (recordOutfitWorn — гонка реакций/носки). */
    public function lockActiveForOwner(int $id, User $wardrobeOwner): ?WardrobeOutfit
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.id = :id')
            ->andWhere('outfit.wardrobeOwner = :owner')
            ->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('owner', $wardrobeOwner)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Живой образ по id БЕЗ ограничения владельца — авторизация (canShare) проверяется
     * вызывающим кодом отдельно (см. WardrobeItemRepository::findActiveOne()).
     */
    public function findActive(int $id): ?WardrobeOutfit
    {
        return $this->createQueryBuilder('outfit')
            ->andWhere('outfit.id = :id')
            ->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Идемпотентность ночного пакетного конвейера: перед вставкой свежего батча
     * гасит (soft-delete) прежние необгашенные образы того же гардероба+повода
     * за тот же день — без этого повторный/ретраенный прогон плодил бы дубли.
     * Физический DELETE запрещён правилом проекта — только deletedAt.
     */
    public function softDeleteDailyBatch(User $wardrobeOwner, string $occasion, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(WardrobeOutfit::class, 'outfit')
            ->set('outfit.deletedAt', ':now')
            ->where('outfit.wardrobeOwner = :owner')
            ->andWhere('outfit.occasion = :occasion')
            ->andWhere('outfit.deletedAt IS NULL')
            ->andWhere('outfit.createdAt >= :from')
            ->andWhere('outfit.createdAt < :to')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('owner', $wardrobeOwner)
            ->setParameter('occasion', $occasion)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->execute();
    }

    /**
     * Сколько образов пользователь создал сам в окне [from, to) — бар квалификации
     * реферальной награды. occasion IS NULL отсекает образы ночного пакетного
     * конвейера (там user=owner, но действие робота, не пользователя).
     */
    public function countCreatedByUserBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('outfit')
            ->select('COUNT(outfit.id)')
            ->where('outfit.user = :user')
            ->andWhere('outfit.createdAt >= :from')
            ->andWhere('outfit.createdAt < :to')
            ->andWhere('outfit.occasion IS NULL')
            ->andWhere('outfit.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
