<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;
use App\Service\Wardrobe\WardrobeStatisticsService;
use PHPUnit\Framework\TestCase;

final class WardrobeStatisticsServiceTest extends TestCase
{
    public function testBuildsActiveArchiveValueAndDistributionStatistics(): void
    {
        $user = (new User())->setEmail('stats@test.local');

        $repository = $this->createMock(WardrobeItemRepository::class);
        $repository->expects(self::once())->method('getStatisticsSummary')->with($user)->willReturn([
            'active' => 2,
            'archived' => 1,
            'totalValue' => 400.0,
            'pricedCount' => 2,
            'loved' => 1,
            'complete' => 1,
        ]);
        $repository->method('getCategoryCounts')->willReturn([
            ['value' => 'Платья', 'cnt' => 1, 'total' => 100.0],
            ['value' => 'Футболки', 'cnt' => 1, 'total' => 300.0],
        ]);
        $repository->method('getSeasonCounts')->willReturn([]);
        $repository->method('getBrandCounts')->willReturn([]);
        $repository->method('getColorCounts')->willReturn([]);
        $repository->method('getCompletionCounts')->willReturn([]);
        $repository->method('getWearStatusCounts')->willReturn([
            ['value' => WardrobeItem::WEAR_GIVEN_AWAY, 'cnt' => 1],
        ]);
        $repository->method('getItemStatusCounts')->willReturn([
            ['value' => WardrobeItem::ITEM_ARCHIVED, 'cnt' => 1],
        ]);

        $statistics = (new WardrobeStatisticsService($repository))->forUser($user);

        self::assertSame(2, $statistics['summary']['active']);
        self::assertSame(1, $statistics['summary']['archived']);
        self::assertSame(400.0, $statistics['summary']['totalValue']);
        self::assertSame(200.0, $statistics['summary']['averagePrice']);
        self::assertSame(1, $statistics['summary']['loved']);
        self::assertSame(50, $statistics['summary']['lovedPercent']);
        self::assertSame(50, $statistics['summary']['completePercent']);
        self::assertSame(['Платья', 'Футболки'], array_column($statistics['categories'], 'label'));
        self::assertContains(WardrobeItem::WEAR_GIVEN_AWAY, array_column($statistics['wearStatuses'], 'key'));
        self::assertContains(WardrobeItem::ITEM_ARCHIVED, array_column($statistics['itemStatuses'], 'key'));
    }

    public function testUnsetValueIsNormalizedToNullKeyRegardlessOfNullOrEmptyString(): void
    {
        $user = (new User())->setEmail('unset@test.local');

        $repository = $this->createMock(WardrobeItemRepository::class);
        $repository->method('getStatisticsSummary')->willReturn([
            'active' => 3, 'archived' => 0, 'totalValue' => 0.0, 'pricedCount' => 0, 'loved' => 0, 'complete' => 0,
        ]);
        $repository->method('getCategoryCounts')->willReturn([
            ['value' => null, 'cnt' => 1, 'total' => 0.0],
            ['value' => '', 'cnt' => 2, 'total' => 0.0],
        ]);
        $repository->method('getSeasonCounts')->willReturn([]);
        $repository->method('getBrandCounts')->willReturn([]);
        $repository->method('getColorCounts')->willReturn([]);
        $repository->method('getCompletionCounts')->willReturn([]);
        $repository->method('getWearStatusCounts')->willReturn([]);
        $repository->method('getItemStatusCounts')->willReturn([]);

        $statistics = (new WardrobeStatisticsService($repository))->forUser($user);

        self::assertCount(1, $statistics['categories']);
        self::assertNull($statistics['categories'][0]['key']);
        self::assertSame('Не указано', $statistics['categories'][0]['label']);
        self::assertSame(3, $statistics['categories'][0]['count']);
    }
}
