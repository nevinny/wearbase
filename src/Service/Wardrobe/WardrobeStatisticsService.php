<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;

final class WardrobeStatisticsService
{
    private const SEASON_LABELS = [
        'all' => 'Всесезон',
        'spring' => 'Весна',
        'summer' => 'Лето',
        'autumn' => 'Осень',
        'winter' => 'Зима',
    ];

    public function __construct(private readonly WardrobeItemRepository $items) {}

    /**
     * @return array{
     *   summary: array{active: int, archived: int, totalValue: float, averagePrice: float, loved: int, lovedPercent: int, completePercent: int},
     *   categories: array<int, array{key: ?string, label: string, count: int, total: float}>,
     *   seasons: array<int, array{key: ?string, label: string, count: int}>,
     *   brands: array<int, array{key: ?string, label: string, count: int}>,
     *   colors: array<int, array{key: ?string, label: string, count: int}>,
     *   completion: array<int, array{key: ?string, label: string, count: int}>,
     *   wearStatuses: array<int, array{key: ?string, label: string, count: int}>,
     *   itemStatuses: array<int, array{key: ?string, label: string, count: int}>
     * }
     */
    public function forUser(User $user): array
    {
        $summary = $this->items->getStatisticsSummary($user);
        $activeCount = $summary['active'];

        return [
            'summary' => [
                'active' => $activeCount,
                'archived' => $summary['archived'],
                'totalValue' => $summary['totalValue'],
                'averagePrice' => $summary['pricedCount'] > 0 ? $summary['totalValue'] / $summary['pricedCount'] : 0.0,
                'loved' => $summary['loved'],
                'lovedPercent' => $this->percent($summary['loved'], $activeCount),
                'completePercent' => $this->percent($summary['complete'], $activeCount),
            ],
            'categories' => $this->groups($this->items->getCategoryCounts($user), [], true),
            'seasons' => $this->groups($this->items->getSeasonCounts($user), self::SEASON_LABELS),
            'brands' => $this->groups($this->items->getBrandCounts($user)),
            'colors' => $this->groups($this->items->getColorCounts($user)),
            'completion' => $this->groups($this->items->getCompletionCounts($user), WardrobeItem::COMPLETION_LABELS),
            'wearStatuses' => $this->groups($this->items->getWearStatusCounts($user), WardrobeItem::WEAR_LABELS),
            'itemStatuses' => $this->groups($this->items->getItemStatusCounts($user), WardrobeItem::ITEM_LABELS),
        ];
    }

    /**
     * Схлопывает SQL-агрегат в отображаемые строки. «Не указано» — это признак
     * (key: null), а не строковый ключ: NULL и '' в БД считаются одной группой.
     *
     * @param array<int, array{value: ?string, cnt: int, total?: float}> $rows
     * @param array<string, string> $labels
     * @return array<int, array{key: ?string, label: string, count: int, total?: float}>
     */
    private function groups(array $rows, array $labels = [], bool $withTotal = false): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $value = $row['value'] ?? null;
            $key = ($value === null || trim((string) $value) === '') ? null : (string) $value;
            $safeKey = $key ?? "\0__unset__";
            $groups[$safeKey] ??= [
                'key' => $key,
                'label' => $key === null ? 'Не указано' : ($labels[$key] ?? $key),
                'count' => 0,
                'total' => 0.0,
            ];
            $groups[$safeKey]['count'] += (int) $row['cnt'];
            if ($withTotal) {
                $groups[$safeKey]['total'] += (float) ($row['total'] ?? 0);
            }
        }
        if (!$withTotal) {
            foreach ($groups as &$group) {
                unset($group['total']);
            }
            unset($group);
        }
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        return $rows;
    }

    private function percent(int $part, int $total): int
    {
        return $total > 0 ? (int) round($part / $total * 100) : 0;
    }
}
