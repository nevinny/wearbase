<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeWearEventRepository;

final class WardrobeStylistContextBuilder
{
    public const EVENTS = ['everyday', 'work', 'school', 'walk', 'celebration', 'sport', 'travel'];
    public const WEATHER_CONDITIONS = ['clear', 'cloudy', 'rain', 'snow', 'wind'];
    public const TEMPERATURE_BANDS = ['freezing', 'cold', 'mild', 'warm', 'hot'];

    public function __construct(
        private readonly WardrobeWearEventRepository $wearEvents,
    ) {}

    /**
     * @param WardrobeItem[] $items
     * @return array{items:WardrobeItem[],rotation:array<int,string>,event:?string,weather:?string}
     */
    public function build(User $subject, array $items, ?string $event, ?string $weatherCondition = null, ?string $temperatureBand = null): array
    {
        $items = array_values(array_filter($items, static fn (WardrobeItem $item): bool =>
            $item->getItemStatus() === WardrobeItem::ITEM_ACTIVE
            && $item->getWearStatus() === WardrobeItem::WEAR_ACTIVE
            && $item->getCleanlinessStatus() === WardrobeItem::CLEANLINESS_CLEAN
        ));
        $recentIds = array_fill_keys($this->wearEvents->recentlyWornItemIds($subject, new \DateTimeImmutable('today -6 days')), true);
        usort($items, static fn (WardrobeItem $a, WardrobeItem $b): int =>
            (int) isset($recentIds[(int) $a->getId()]) <=> (int) isset($recentIds[(int) $b->getId()])
        );
        $rotation = [];
        foreach ($items as $item) {
            $rotation[(int) $item->getId()] = isset($recentIds[(int) $item->getId()]) ? 'recent' : 'fresh';
        }
        $weather = in_array($weatherCondition, self::WEATHER_CONDITIONS, true)
            && in_array($temperatureBand, self::TEMPERATURE_BANDS, true)
            ? sprintf('condition:%s;temperature:%s', $weatherCondition, $temperatureBand)
            : null;

        return [
            'items' => $items,
            'rotation' => $rotation,
            'event' => in_array($event, self::EVENTS, true) ? $event : null,
            'weather' => $weather,
        ];
    }
}
