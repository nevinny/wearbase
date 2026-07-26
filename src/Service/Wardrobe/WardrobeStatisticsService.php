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
     *   categories: array<int, array{key: string, label: string, count: int, total: float}>,
     *   seasons: array<int, array{key: string, label: string, count: int}>,
     *   brands: array<int, array{key: string, label: string, count: int}>,
     *   colors: array<int, array{key: string, label: string, count: int}>,
     *   completion: array<int, array{key: string, label: string, count: int}>,
     *   wearStatuses: array<int, array{key: string, label: string, count: int}>,
     *   itemStatuses: array<int, array{key: string, label: string, count: int}>
     * }
     */
    public function forUser(User $user): array
    {
        $all = $this->items->findForStatistics($user);
        $notArchived = array_values(array_filter(
            $all,
            static fn (WardrobeItem $item): bool => $item->getItemStatus() !== WardrobeItem::ITEM_ARCHIVED,
        ));
        $active = array_values(array_filter(
            $notArchived,
            static fn (WardrobeItem $item): bool =>
                $item->getWearStatus() !== WardrobeItem::WEAR_GIVEN_AWAY,
        ));

        $totalValue = 0.0;
        $pricedCount = 0;
        $loved = 0;
        $complete = 0;
        foreach ($active as $item) {
            if ($item->getPrice() !== null) {
                $totalValue += (float) $item->getPrice();
                ++$pricedCount;
            }
            $loved += $item->getLoveAtFirstSight() === WardrobeItem::LOVE_YES ? 1 : 0;
            $complete += $item->getCompletionStatus() === WardrobeItem::COMPLETION_COMPLETE ? 1 : 0;
        }

        $activeCount = count($active);

        return [
            'summary' => [
                'active' => $activeCount,
                'archived' => count(array_filter($all, static fn (WardrobeItem $item): bool => $item->getItemStatus() === WardrobeItem::ITEM_ARCHIVED)),
                'totalValue' => $totalValue,
                'averagePrice' => $pricedCount > 0 ? $totalValue / $pricedCount : 0.0,
                'loved' => $loved,
                'lovedPercent' => $this->percent($loved, $activeCount),
                'completePercent' => $this->percent($complete, $activeCount),
            ],
            'categories' => $this->categoryGroups($active),
            'seasons' => $this->groups($active, static fn (WardrobeItem $item): ?string => $item->getSeason(), self::SEASON_LABELS),
            'brands' => $this->groups($active, static fn (WardrobeItem $item): ?string => $item->getCustomBrandName()),
            'colors' => $this->groups($active, static fn (WardrobeItem $item): ?string => $item->getColorName()),
            'completion' => $this->groups($active, static fn (WardrobeItem $item): ?string => $item->getCompletionStatus(), WardrobeItem::COMPLETION_LABELS),
            'wearStatuses' => $this->groups($notArchived, static fn (WardrobeItem $item): ?string => $item->getWearStatus(), WardrobeItem::WEAR_LABELS),
            'itemStatuses' => $this->groups($all, static fn (WardrobeItem $item): ?string => $item->getItemStatus(), WardrobeItem::ITEM_LABELS),
        ];
    }

    /** @param WardrobeItem[] $items
     *  @return array<int, array{key: string, label: string, count: int, total: float}>
     */
    private function categoryGroups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = $this->value($item->getCategory());
            $groups[$key] ??= ['key' => $key, 'label' => $key, 'count' => 0, 'total' => 0.0];
            ++$groups[$key]['count'];
            $groups[$key]['total'] += (float) ($item->getPrice() ?? 0);
        }
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        return $rows;
    }

    /**
     * @param WardrobeItem[] $items
     * @param callable(WardrobeItem): ?string $extractor
     * @param array<string, string> $labels
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function groups(array $items, callable $extractor, array $labels = []): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = $this->value($extractor($item));
            $groups[$key] ??= ['key' => $key, 'label' => $labels[$key] ?? $key, 'count' => 0];
            ++$groups[$key]['count'];
        }
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        return $rows;
    }

    private function value(?string $value): string
    {
        return $value === null || trim($value) === '' ? 'Не указано' : $value;
    }

    private function percent(int $part, int $total): int
    {
        return $total > 0 ? (int) round($part / $total * 100) : 0;
    }
}
