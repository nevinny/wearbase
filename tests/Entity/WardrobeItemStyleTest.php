<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BrandStyle;
use App\Entity\WardrobeItem;
use PHPUnit\Framework\TestCase;

final class WardrobeItemStyleTest extends TestCase
{
    public function testStylesUseExistingAdminDirectoryEntities(): void
    {
        $smartCasual = (new BrandStyle())->setTitle('Smart casual');
        $minimalism = (new BrandStyle())->setTitle('Минимализм');
        $item = (new WardrobeItem())
            ->addStyle($smartCasual)
            ->addStyle($minimalism)
            ->addStyle($minimalism);

        self::assertCount(2, $item->getStyles());
        self::assertSame(['Smart casual', 'Минимализм'], $item->getStyleLabels());

        $item->removeStyle($smartCasual);
        self::assertSame([$minimalism], array_values($item->getStyles()->toArray()));
    }
}
