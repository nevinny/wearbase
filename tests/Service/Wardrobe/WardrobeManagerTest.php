<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Repository\WardrobeRepository;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WardrobeManagerTest extends TestCase
{
    public function testCompletionMovesFromDraftToBasicAndComplete(): void
    {
        $manager = new WardrobeManager(
            $this->createMock(WardrobeRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        $item = new WardrobeItem();

        self::assertSame(WardrobeItem::COMPLETION_DRAFT, $manager->refreshCompletionStatus($item));

        $item
            ->setName('Белая футболка')
            ->setCategory('Футболка')
            ->setProductUrl('https://example.com/item')
            ->setSize('M');

        self::assertSame(WardrobeItem::COMPLETION_BASIC, $manager->refreshCompletionStatus($item));

        $item
            ->setPhoto('shirt.jpg')
            ->setCustomBrandName('Local Brand')
            ->setColorName('Белый')
            ->setMaterialText('100% хлопок')
            ->setPrice('2990.00')
            ->setPurchaseReason('Базовая вещь')
            ->setCareText('Стирка при 30 °C');

        self::assertSame(WardrobeItem::COMPLETION_COMPLETE, $manager->refreshCompletionStatus($item));
        self::assertSame(WardrobeItem::COMPLETION_COMPLETE, $item->getCompletionStatus());
    }
}
