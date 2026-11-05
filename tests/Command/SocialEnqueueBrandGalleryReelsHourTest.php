<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SocialEnqueueBrandGalleryCommand;
use App\Service\Social\SocialRubrics;
use PHPUnit\Framework\TestCase;

final class SocialEnqueueBrandGalleryReelsHourTest extends TestCase
{
    public function testBrandReelsSlotsAreSpreadNotConsecutive(): void
    {
        $def = (new SocialRubrics())->get('brand_reels');
        self::assertNotNull($def, 'рубрика brand_reels должна существовать');

        $first = SocialEnqueueBrandGalleryCommand::reelsHour($def, 0);
        $second = SocialEnqueueBrandGalleryCommand::reelsHour($def, 1);

        self::assertGreaterThanOrEqual(3, $second - $first, 'два Reels дня не должны идти подряд');
    }

    public function testFallsBackToConsecutiveHoursBeyondTheList(): void
    {
        self::assertSame(13, SocialEnqueueBrandGalleryCommand::reelsHour(['hours' => [13, 18]], 0));
        self::assertSame(18, SocialEnqueueBrandGalleryCommand::reelsHour(['hours' => [13, 18]], 1));
        self::assertSame(19, SocialEnqueueBrandGalleryCommand::reelsHour(['hours' => [13, 18]], 2));
        self::assertSame(20, SocialEnqueueBrandGalleryCommand::reelsHour(['hour' => 19], 1));
    }
}
