<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeWearEventRepository;
use App\Service\Wardrobe\WardrobeStylistContextBuilder;
use App\Service\Wardrobe\WardrobeWeatherContextProviderInterface;
use PHPUnit\Framework\TestCase;

final class WardrobeStylistContextBuilderTest extends TestCase
{
    public function testExcludesUnavailableItemsAndRotatesRecentWearLast(): void
    {
        $fresh = $this->item(1);
        $recent = $this->item(2);
        $repair = $this->item(3)->setItemStatus(WardrobeItem::ITEM_REPAIR);
        $reserve = $this->item(4)->setWearStatus(WardrobeItem::WEAR_RESERVE);
        $wears = $this->createMock(WardrobeWearEventRepository::class);
        $wears->expects(self::once())->method('recentlyWornItemIds')->willReturn([2]);
        $weather = $this->createStub(WardrobeWeatherContextProviderInterface::class);

        $context = (new WardrobeStylistContextBuilder($wears, $weather))
            ->build(new User(), [$recent, $repair, $fresh, $reserve], 'work');

        self::assertSame([$fresh, $recent], $context['items']);
        self::assertSame([1 => 'fresh', 2 => 'recent'], $context['rotation']);
        self::assertSame('work', $context['event']);
    }

    public function testUnknownEventAndWeatherFallBackToNull(): void
    {
        $wears = $this->createStub(WardrobeWearEventRepository::class);
        $wears->method('recentlyWornItemIds')->willReturn([]);
        $weather = $this->createStub(WardrobeWeatherContextProviderInterface::class);
        $weather->method('current')->willReturn('Moscow: user location');

        $context = (new WardrobeStylistContextBuilder($wears, $weather))
            ->build(new User(), [$this->item(1), $this->item(2)], 'private-event-name');

        self::assertNull($context['event']);
        self::assertNull($context['weather']);
    }

    private function item(int $id): WardrobeItem
    {
        $item = (new WardrobeItem())->setCategory('Тест');
        (new \ReflectionProperty(WardrobeItem::class, 'id'))->setValue($item, $id);
        return $item;
    }
}
