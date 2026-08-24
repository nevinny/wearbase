<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeWearEventRepository;

final class WardrobeStylistContextBuilder
{
    public const EVENTS = ['everyday', 'work', 'school', 'walk', 'celebration', 'sport', 'travel'];
    private const WEATHER = ['cold', 'mild', 'hot', 'rain', 'snow', 'wind'];

    public function __construct(
        private readonly WardrobeWearEventRepository $wearEvents,
        private readonly WardrobeWeatherContextProviderInterface $weather,
    ) {}

    /**
     * @param WardrobeItem[] $items
     * @return array{items:WardrobeItem[],rotation:array<int,string>,event:?string,weather:?string}
     */
    public function build(User $subject, array $items, ?string $event): array
    {
        $items = array_values(array_filter($items, static fn (WardrobeItem $item): bool =>
            $item->getItemStatus() === WardrobeItem::ITEM_ACTIVE
            && $item->getWearStatus() === WardrobeItem::WEAR_ACTIVE
        ));
        $recentIds = array_fill_keys($this->wearEvents->recentlyWornItemIds($subject, new \DateTimeImmutable('today -6 days')), true);
        usort($items, static fn (WardrobeItem $a, WardrobeItem $b): int =>
            (int) isset($recentIds[(int) $a->getId()]) <=> (int) isset($recentIds[(int) $b->getId()])
        );
        $rotation = [];
        foreach ($items as $item) {
            $rotation[(int) $item->getId()] = isset($recentIds[(int) $item->getId()]) ? 'recent' : 'fresh';
        }
        $weather = $this->weather->current();

        return [
            'items' => $items,
            'rotation' => $rotation,
            'event' => in_array($event, self::EVENTS, true) ? $event : null,
            'weather' => in_array($weather, self::WEATHER, true) ? $weather : null,
        ];
    }
}
