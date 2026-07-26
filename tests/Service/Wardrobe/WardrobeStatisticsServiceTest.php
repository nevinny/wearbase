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
        $active = (new WardrobeItem())
            ->setCategory('Платья')->setSeason('summer')
            ->setCustomBrandName('Brand A')->setColorName('Красный')
            ->setPrice('100.00')->setLoveAtFirstSight(WardrobeItem::LOVE_YES)
            ->setCompletionStatus(WardrobeItem::COMPLETION_COMPLETE);
        $reserve = (new WardrobeItem())
            ->setCategory('Футболки')->setSeason('all')
            ->setPrice('300.00')->setWearStatus(WardrobeItem::WEAR_RESERVE);
        $archived = (new WardrobeItem())
            ->setCategory('Архив')->setPrice('500.00')
            ->setItemStatus(WardrobeItem::ITEM_ARCHIVED);
        $givenAway = (new WardrobeItem())
            ->setCategory('Передано')->setPrice('700.00')
            ->setWearStatus(WardrobeItem::WEAR_GIVEN_AWAY);

        $repository = $this->createMock(WardrobeItemRepository::class);
        $repository->expects(self::once())
            ->method('findForStatistics')
            ->with($user)
            ->willReturn([$active, $reserve, $archived, $givenAway]);

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
}
