<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeItem>
 */
class WardrobeItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeItem::class);
    }

    /**
     * @return WardrobeItem[]
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->orderBy('w.itemNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return WardrobeItem[] */
    public function findArchivedForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus = :archived')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->orderBy('w.itemNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{q?: string, category?: string, brand?: string, color?: string, size?: string, season?: string, completion?: string, status?: string, wear?: string} $filters
     * @return WardrobeItem[]
     */
    public function searchForUser(User $user, array $filters, bool $archived = false): array
    {
        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere($archived ? 'w.itemStatus = :archived' : 'w.itemStatus != :archived')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED);

        if (!$archived) {
            $qb->andWhere('w.wearStatus != :givenAway')
                ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY);
        }

        $this->applyFilters($qb, $filters);

        return $qb->orderBy('w.itemNo', 'DESC')->getQuery()->getResult();
    }

    /**
     * Значения выпадающих списков принадлежат только выбранному члену семьи.
     *
     * @return array{categories: string[], brands: string[], colors: string[], sizes: string[], seasons: string[]}
     */
    public function getFilterOptions(User $user, bool $archived = false): array
    {
        return [
            'categories' => $this->distinctFieldValues($user, 'category', $archived),
            'brands' => $this->distinctFieldValues($user, 'customBrandName', $archived),
            'colors' => $this->distinctFieldValues($user, 'colorName', $archived),
            'sizes' => $this->distinctFieldValues($user, 'size', $archived),
            'seasons' => $this->distinctFieldValues($user, 'season', $archived),
        ];
    }

    /** @return WardrobeItem[] */
    public function findForStatistics(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('w.itemNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Вещи, отданные из семьи (терминальный wear_status, НЕ deleted).
     *
     * @return WardrobeItem[]
     */
    public function findGivenAwayForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.wearStatus = :givenAway')
            ->setParameter('user', $user)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->orderBy('w.itemNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Число активных вещей (для карточек членов семьи).
     */
    public function countActiveForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findActiveOneForUser(int $id, User $user): ?WardrobeItem
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Активная вещь по id БЕЗ ограничения владельца — авторизация (свой /
     * FamilyService::canManage) проверяется вызывающим кодом отдельно
     * (напр. переиспользование AI-подсказок по уже сохранённому фото).
     */
    public function findActiveOne(int $id): ?WardrobeItem
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Статистика по категориям: [['category' => ..., 'cnt' => ..., 'total' => ...], ...]
     *
     * @return array<int, array{category: string, cnt: int, total: string}>
     */
    public function getStats(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->select("COALESCE(NULLIF(w.category, ''), 'Без категории') AS category", 'COUNT(w.id) AS cnt', 'COALESCE(SUM(w.price), 0) AS total')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->groupBy('category')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Следующий сквозной номер вещи (по всем записям юзера, включая soft-deleted).
     */
    public function nextItemNo(User $user): int
    {
        $max = $this->createQueryBuilder('w')
            ->select('COALESCE(MAX(w.itemNo), 0)')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }

    /**
     * @return string[]
     */
    public function distinctCategories(User $user): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('DISTINCT w.category')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }

    /** @param array<string, string> $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $fields = [
            'category' => 'category',
            'brand' => 'customBrandName',
            'color' => 'colorName',
            'size' => 'size',
            'season' => 'season',
            'completion' => 'completionStatus',
            'status' => 'itemStatus',
            'wear' => 'wearStatus',
        ];
        foreach ($fields as $filter => $field) {
            if (($filters[$filter] ?? '') !== '') {
                $qb->andWhere(sprintf('w.%s = :filter_%s', $field, $filter))
                    ->setParameter('filter_'.$filter, $filters[$filter]);
            }
        }

        $query = trim($filters['q'] ?? '');
        if ($query === '') {
            return;
        }

        $or = $qb->expr()->orX();
        $variants = array_values(array_unique([
            $query,
            mb_strtolower($query),
            mb_strtoupper($query),
            mb_convert_case($query, MB_CASE_TITLE),
        ]));
        foreach ($variants as $index => $variant) {
            $parameter = 'query_'.$index;
            foreach (['name', 'customBrandName', 'category', 'colorName', 'size'] as $field) {
                $or->add(sprintf('w.%s LIKE :%s', $field, $parameter));
            }
            $qb->setParameter($parameter, '%'.$variant.'%');
        }
        $number = ltrim($query, "# \t\n\r\0\x0B");
        if ($number !== '' && ctype_digit($number)) {
            $or->add('w.itemNo = :itemNo');
            $qb->setParameter('itemNo', (int) $number);
        }
        // Несколько вариантов регистра нужны тестовому SQLite; MySQL unicode_ci
        // сопоставляет их регистронезависимо.
        $qb->andWhere($or);
    }

    /** @return string[] */
    private function distinctFieldValues(User $user, string $field, bool $archived): array
    {
        if (!in_array($field, ['category', 'customBrandName', 'colorName', 'size', 'season'], true)) {
            throw new \InvalidArgumentException('Unsupported wardrobe filter field.');
        }

        $qb = $this->createQueryBuilder('w')
            ->select('DISTINCT w.'.$field)
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.'.$field.' IS NOT NULL')
            ->andWhere('w.'.$field." != ''")
            ->andWhere($archived ? 'w.itemStatus = :archived' : 'w.itemStatus != :archived')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->orderBy('w.'.$field, 'ASC');
        if (!$archived) {
            $qb->andWhere('w.wearStatus != :givenAway')
                ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY);
        }

        return array_map('strval', $qb->getQuery()->getSingleColumnResult());
    }
}
