<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeWearEventRepository;
use App\Service\Wardrobe\WardrobeStylistContextBuilder;
use PHPUnit\Framework\TestCase;

final class WardrobeStylistContextBuilderTest extends TestCase
{
    public function testExcludesUnavailableItemsAndRotatesRecentWearLast(): void
    {
        $fresh = $this->item(1);
        $recent = $this->item(2);
        $repair = $this->item(3)->setItemStatus(WardrobeItem::ITEM_REPAIR);
        $reserve = $this->item(4)->setWearStatus(WardrobeItem::WEAR_RESERVE);
        $dirty = $this->item(5)->setCleanlinessStatus(WardrobeItem::CLEANLINESS_DIRTY);
        $laundry = $this->item(6)->setCleanlinessStatus(WardrobeItem::CLEANLINESS_LAUNDRY);
        $wears = $this->createMock(WardrobeWearEventRepository::class);
        $wears->expects(self::once())->method('recentlyWornItemIds')->willReturn([2]);
        $context = (new WardrobeStylistContextBuilder($wears))
            ->build(new User(), [$recent, $repair, $fresh, $reserve, $dirty, $laundry], 'work', 'rain', 'cold');

        self::assertSame([$fresh, $recent], $context['items']);
        self::assertSame([1 => 'fresh', 2 => 'recent'], $context['rotation']);
        self::assertSame('work', $context['event']);
        self::assertSame('condition:rain;temperature:cold', $context['weather']);
    }

    public function testUnknownEventAndWeatherFallBackToNull(): void
    {
        $wears = $this->createStub(WardrobeWearEventRepository::class);
        $wears->method('recentlyWornItemIds')->willReturn([]);
        $context = (new WardrobeStylistContextBuilder($wears))
            ->build(new User(), [$this->item(1), $this->item(2)], 'private-event-name', 'Moscow: user location', '17.3');

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
