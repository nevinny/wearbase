<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SocialPost;
use PHPUnit\Framework\TestCase;

/**
 * media_path хранит слайды карусели построчно; одиночная картинка — та же строка без \n,
 * поэтому старые записи читаются как карусель из одного слайда (совместимость с publish-tick).
 */
class SocialPostMediaPathsTest extends TestCase
{
    public function testNoMediaGivesEmptyList(): void
    {
        self::assertSame([], (new SocialPost())->getMediaPaths());
    }

    public function testLegacySingleValueReadsAsOneSlide(): void
    {
        $post = (new SocialPost())->setMediaPath('/images/social/card-75.png');

        self::assertSame(['/images/social/card-75.png'], $post->getMediaPaths());
    }

    public function testSlidesRoundTripInOrder(): void
    {
        $slides = ['/images/social/1.png', '/images/social/2.png', '/images/social/3.png'];
        $post = (new SocialPost())->setMediaPaths($slides);

        self::assertSame($slides, $post->getMediaPaths());
        self::assertSame(implode("\n", $slides), $post->getMediaPath());
    }

    public function testBlankEntriesDroppedOnBothSides(): void
    {
        $post = (new SocialPost())->setMediaPaths(['/a.png', '  ', '/b.png']);
        self::assertSame(['/a.png', '/b.png'], $post->getMediaPaths());

        $post->setMediaPaths([]);
        self::assertNull($post->getMediaPath());

        $post->setMediaPath("/a.png\n\n/b.png\n");
        self::assertSame(['/a.png', '/b.png'], $post->getMediaPaths());
    }
}
