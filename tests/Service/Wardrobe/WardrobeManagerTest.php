<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Entity\User;
use App\Repository\WardrobeRepository;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WardrobeManagerTest extends TestCase
{
    public function testDefaultWardrobeIsMemoizedBeforeFlush(): void
    {
        $owner = (new User())->setEmail('memo@test.local');
        $repository = $this->createMock(WardrobeRepository::class);
        $repository->expects(self::once())->method('findDefaultForOwner')->with($owner)->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $manager = new WardrobeManager($repository, $entityManager);

        $first = $manager->getOrCreateDefault($owner);
        $second = $manager->getOrCreateDefault($owner);

        self::assertSame($first, $second);
        self::assertSame($owner, $first->getOwner());
    }

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
        self::assertSame('Заполнено полностью', $item->getCompletionStatusLabel());
    }

    public function testClearingCategoryReferenceAlsoClearsLegacyCategory(): void
    {
        $category = (new \App\Entity\WardrobeCategory())->setCode('tops')->setName('Верх');
        $item = (new WardrobeItem())->setCategoryRef($category);

        self::assertSame('Верх', $item->getCategory());

        $item->setCategoryRef(null);

        self::assertNull($item->getCategory());
    }

    public function testArchiveAndRestoreAreRecoverable(): void
    {
        $repository = $this->createMock(WardrobeRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');
        $manager = new WardrobeManager($repository, $entityManager);
        $item = new WardrobeItem();

        $manager->archive($item);
        self::assertSame(WardrobeItem::ITEM_ARCHIVED, $item->getItemStatus());
        self::assertFalse($item->isDeleted());

        $manager->restore($item);
        self::assertSame(WardrobeItem::ITEM_ACTIVE, $item->getItemStatus());
        self::assertFalse($item->isDeleted());
    }
}
