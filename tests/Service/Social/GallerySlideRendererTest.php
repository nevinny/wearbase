<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Entity\SocialPost;
use App\Service\Social\GallerySlideRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Нормализация слайдов и позиция логотипа — сердце A/B: если холст не один, Instagram
 * кадрирует карусель по первому слайду и эксперимент сравнивает не то, что задумано.
 */
class GallerySlideRendererTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wb-slides-' . getmypid();
        @mkdir($this->projectDir . '/public_html/images/brands', 0775, true);
        @mkdir($this->projectDir . '/public_html/images/logos', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    public function testSlidesNormalizedToSingleCanvas(): void
    {
        // Разные пропорции: горизонталь, вертикаль, квадрат.
        $this->makeImage('/public_html/images/brands/a.jpg', 1600, 900);
        $this->makeImage('/public_html/images/brands/b.jpg', 600, 1400);
        $this->makeImage('/public_html/images/brands/c.jpg', 800, 800);

        $slides = $this->renderer()->render(
            $this->post(1, brandWithLogo: false),
            ['/images/brands/a.jpg', '/images/brands/b.jpg', '/images/brands/c.jpg'],
            logoFirst: false,
        );

        self::assertCount(3, $slides);
        foreach ($slides as $slide) {
            $size = getimagesize($this->projectDir . '/public_html' . $slide);
            self::assertSame([GallerySlideRenderer::WIDTH, GallerySlideRenderer::HEIGHT], [$size[0], $size[1]], $slide);
        }
    }

    public function testLogoSlideGoesFirstOrLastByVariant(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 1000, 1000);
        $this->makeImage('/public_html/images/brands/b.jpg', 1000, 1000);
        $this->makeImage('/public_html/images/logos/logo.jpg', 400, 200);

        $first = $this->renderer()->render($this->post(10), ['/images/brands/a.jpg', '/images/brands/b.jpg'], logoFirst: true);
        $last = $this->renderer()->render($this->post(11), ['/images/brands/a.jpg', '/images/brands/b.jpg'], logoFirst: false);

        self::assertCount(3, $first);
        self::assertCount(3, $last);
        self::assertStringContainsString('-logo.jpg', $first[0]);
        self::assertStringContainsString('-logo.jpg', $last[2]);
        // Фото в обеих ветках идут в одном порядке — различается только позиция логотипа.
        self::assertSame(['p10-01.jpg', 'p10-02.jpg'], array_map('basename', [$first[1], $first[2]]));
        self::assertSame(['p11-01.jpg', 'p11-02.jpg'], array_map('basename', [$last[0], $last[1]]));
    }

    public function testMissingSourcesAndLogoDegradeGracefully(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 900, 1100);

        // Логотипа на диске нет → слайд логотипа просто не добавляется.
        $slides = $this->renderer()->render($this->post(20), ['/images/brands/a.jpg', '/images/brands/gone.jpg'], logoFirst: true);

        self::assertCount(1, $slides);
        self::assertStringContainsString('p20-01.jpg', $slides[0]);
    }

    private function renderer(): GallerySlideRenderer
    {
        return new GallerySlideRenderer($this->projectDir, __DIR__ . '/../../../config/social/fonts/NotoSans.ttf');
    }

    private function post(int $id, bool $brandWithLogo = true): SocialPost
    {
        $brand = (new Brand())->setTitle('Тест');
        if ($brandWithLogo) {
            $brand->setLogo('logo.jpg');
        }

        $post = (new SocialPost())->setBrand($brand);

        // id проставляется БД — в юнит-тесте задаём рефлексией, он нужен для имён файлов.
        $ref = new \ReflectionProperty(SocialPost::class, 'id');
        $ref->setValue($post, $id);

        return $post;
    }

    private function makeImage(string $relPath, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 120, 130, 140));
        imagejpeg($im, $this->projectDir . $relPath, 80);
        imagedestroy($im);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
