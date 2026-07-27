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
    /**
     * Статусы, при которых вещь считается «неактивной» и попадает в архив
     * (сама вещь физически не удаляется — это отдельный от soft-delete срез).
     */
    private const ARCHIVE_STATUSES = [
        WardrobeItem::ITEM_ARCHIVED,
        WardrobeItem::ITEM_SOLD,
        WardrobeItem::ITEM_DONATED,
        WardrobeItem::ITEM_LOST,
    ];

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
            ->andWhere('w.itemStatus NOT IN (:archiveStatuses)')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
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
        // leftJoin+addSelect тянет photos одним запросом (карточка списка читает
        // item.coverPhoto на каждую вещь — без fetch join это N+1); distinct() —
        // чтобы root-строки не размножались по join'у на количество фото.
        $qb = $this->createQueryBuilder('w')
            ->leftJoin('w.photos', 'p')
            ->addSelect('p')
            ->distinct()
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere($archived ? 'w.itemStatus IN (:archiveStatuses)' : 'w.itemStatus NOT IN (:archiveStatuses)')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES);

        // Отданные из семьи вещи по умолчанию скрыты из основного списка, но
        // остаются достижимы через явный фильтр wear=given_away (статистика
        // на них ссылается) — иначе active + given_away вообще не видна нигде в UI.
        if (!$archived && ($filters['wear'] ?? '') !== WardrobeItem::WEAR_GIVEN_AWAY) {
            $qb->andWhere('w.wearStatus != :givenAway')
                ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY);
        }

        $this->applyFilters($qb, $filters);

        // Явный orderBy на root отключает автоприменение #[ORM\OrderBy] у w.photos —
        // дублируем его вручную, чтобы getCoverPhoto()/getActivePhotos() видели тот же
        // порядок, что и при обычной ленивой загрузке (show-страница).
        return $qb->orderBy('w.itemNo', 'DESC')
            ->addOrderBy('p.isCover', 'DESC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * Сводка для дашборда статистики одним SQL-агрегатом — вместо гидратации
     * всех вещей юзера (и всей семьи) в PHP на каждый GET.
     *
     * @return array{active: int, archived: int, totalValue: float, pricedCount: int, loved: int, complete: int}
     */
    public function getStatisticsSummary(User $user): array
    {
        $row = $this->createQueryBuilder('w')
            ->select(
                'SUM(CASE WHEN w.itemStatus != :archived AND w.wearStatus != :givenAway THEN 1 ELSE 0 END) AS active',
                'SUM(CASE WHEN w.itemStatus = :archived THEN 1 ELSE 0 END) AS archived',
                'SUM(CASE WHEN w.itemStatus != :archived AND w.wearStatus != :givenAway THEN COALESCE(w.price, 0) ELSE 0 END) AS totalValue',
                'SUM(CASE WHEN w.itemStatus != :archived AND w.wearStatus != :givenAway AND w.price IS NOT NULL THEN 1 ELSE 0 END) AS pricedCount',
                'SUM(CASE WHEN w.itemStatus != :archived AND w.wearStatus != :givenAway AND w.loveAtFirstSight = :loveYes THEN 1 ELSE 0 END) AS loved',
                'SUM(CASE WHEN w.itemStatus != :archived AND w.wearStatus != :givenAway AND w.completionStatus = :complete THEN 1 ELSE 0 END) AS complete',
            )
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->setParameter('loveYes', WardrobeItem::LOVE_YES)
            ->setParameter('complete', WardrobeItem::COMPLETION_COMPLETE)
            ->getQuery()
            ->getSingleResult();

        return [
            'active' => (int) $row['active'],
            'archived' => (int) $row['archived'],
            'totalValue' => (float) $row['totalValue'],
            'pricedCount' => (int) $row['pricedCount'],
            'loved' => (int) $row['loved'],
            'complete' => (int) $row['complete'],
        ];
    }

    /** @return array<int, array{value: ?string, cnt: int, total: float}> */
    public function getCategoryCounts(User $user): array
    {
        return $this->activeGroupCounts($user, 'category', true);
    }

    /** @return array<int, array{value: ?string, cnt: int}> */
    public function getSeasonCounts(User $user): array
    {
        return $this->activeGroupCounts($user, 'season');
    }

    /** @return array<int, array{value: ?string, cnt: int}> */
    public function getBrandCounts(User $user): array
    {
        return $this->activeGroupCounts($user, 'customBrandName');
    }

    /** @return array<int, array{value: ?string, cnt: int}> */
    public function getColorCounts(User $user): array
    {
        return $this->activeGroupCounts($user, 'colorName');
    }

    /** @return array<int, array{value: ?string, cnt: int}> */
    public function getCompletionCounts(User $user): array
    {
        return $this->activeGroupCounts($user, 'completionStatus');
    }

    /**
     * Статус носки среди неархивных вещей (given_away включая — терминальный
     * срез, но это не архив; см. wearStatus, не itemStatus).
     *
     * @return array<int, array{value: ?string, cnt: int}>
     */
    public function getWearStatusCounts(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->select('w.wearStatus AS value', 'COUNT(w.id) AS cnt')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->groupBy('w.wearStatus')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Состояние вещи среди всех (включая архив/проданные/переданные) — сводка «Состояние вещей».
     *
     * @return array<int, array{value: ?string, cnt: int}>
     */
    public function getItemStatusCounts(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->select('w.itemStatus AS value', 'COUNT(w.id) AS cnt')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('w.itemStatus')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array<int, array{value: ?string, cnt: int, total?: float}>
     */
    private function activeGroupCounts(User $user, string $field, bool $withTotal = false): array
    {
        if (!in_array($field, ['category', 'season', 'customBrandName', 'colorName', 'completionStatus'], true)) {
            throw new \InvalidArgumentException('Unsupported wardrobe statistics field.');
        }

        $qb = $this->createQueryBuilder('w')
            ->select('w.'.$field.' AS value', 'COUNT(w.id) AS cnt')
            ->andWhere('w.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus != :archived')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archived', WardrobeItem::ITEM_ARCHIVED)
            ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY)
            ->groupBy('w.'.$field);

        if ($withTotal) {
            $qb->addSelect('COALESCE(SUM(w.price), 0) AS total');
        }

        return $qb->getQuery()->getArrayResult();
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
            ->andWhere('w.itemStatus NOT IN (:archiveStatuses)')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES)
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
            ->andWhere('w.itemStatus NOT IN (:archiveStatuses)')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES)
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
            ->andWhere('w.itemStatus NOT IN (:archiveStatuses)')
            ->andWhere('w.wearStatus != :givenAway')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES)
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
            ->andWhere($archived ? 'w.itemStatus IN (:archiveStatuses)' : 'w.itemStatus NOT IN (:archiveStatuses)')
            ->setParameter('user', $user)
            ->setParameter('archiveStatuses', self::ARCHIVE_STATUSES)
            ->orderBy('w.'.$field, 'ASC');
        if (!$archived) {
            $qb->andWhere('w.wearStatus != :givenAway')
                ->setParameter('givenAway', WardrobeItem::WEAR_GIVEN_AWAY);
        }

        return array_map('strval', $qb->getQuery()->getSingleColumnResult());
    }
}
