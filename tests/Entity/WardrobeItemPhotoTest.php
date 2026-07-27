<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use PHPUnit\Framework\TestCase;

final class WardrobeItemPhotoTest extends TestCase
{
    public function testActiveGallerySelectsExplicitCoverAndIgnoresDeletedPhotos(): void
    {
        $item = new WardrobeItem();
        $first = (new WardrobeItemPhoto())->setFilePath('first.jpg');
        $cover = (new WardrobeItemPhoto())->setFilePath('cover.jpg')->setIsCover(true);
        $deleted = (new WardrobeItemPhoto())->setFilePath('deleted.jpg')->setIsCover(true);
        $deleted->softDelete();

        $item->addPhoto($first)->addPhoto($cover)->addPhoto($deleted);

        self::assertSame([$first, $cover], $item->getActivePhotos());
        self::assertSame($cover, $item->getCoverPhoto());
        self::assertSame($item, $cover->getItem());
    }
}
