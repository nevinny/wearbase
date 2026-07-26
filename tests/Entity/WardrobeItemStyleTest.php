<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WardrobeItem;
use PHPUnit\Framework\TestCase;

final class WardrobeItemStyleTest extends TestCase
{
    public function testStylesUseStableCodesAndDisplayLabels(): void
    {
        $item = (new WardrobeItem())->setStyles(['smart_casual', 'minimalism', 'invalid', 'minimalism']);

        self::assertCount(20, WardrobeItem::STYLE_LABELS);
        self::assertSame(['smart_casual', 'minimalism'], $item->getStyles());
        self::assertSame(['🌶 Smart casual', '🌶 Минимализм'], $item->getStyleLabels());
    }
}
