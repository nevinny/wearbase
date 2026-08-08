<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Entity\BrandImage;
use App\Service\Social\BrandGalleryImages;
use PHPUnit\Framework\TestCase;

/**
 * Порядок слайдов галереи/Reels по frame_kind (app:social:classify-frames):
 * product_person → product_flat → NULL (не классифицирован) → scene → other (дно, только
 * при нехватке MIN_SLIDES). Внутри product_person вертикальный кадр — первый (обложка/
 * кадрирование карусели). Живой инцидент: тёмный горизонтальный лукбук первым слайдом убивал
 * удержание в первые 1.5с — эта сортировка чинит именно это.
 */
class BrandGalleryImagesTest extends TestCase
{
    private string $projectDir;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wb-gallery-' . getmypid() . '-' . uniqid();
        @mkdir($this->projectDir . '/public_html/images/brands', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    public function testVerticalProductPersonBubblesFirst(): void
    {
        $brand = $this->brand([
            $this->image('a.jpg', BrandImage::FRAME_PRODUCT_PERSON, 1200, 900),  // горизонталь
            $this->image('b.jpg', BrandImage::FRAME_PRODUCT_PERSON, 900, 1200),  // вертикаль — должна быть первой
            $this->image('c.jpg', BrandImage::FRAME_PRODUCT_FLAT, 900, 1200),
        ]);

        $paths = $this->service()->paths($brand);

        self::assertSame(['/images/brands/b.jpg', '/images/brands/a.jpg', '/images/brands/c.jpg'], $paths);
    }

    public function testOtherIsExcludedWhenCoreAlreadyMeetsMinSlides(): void
    {
        $brand = $this->brand([
            $this->image('a.jpg', BrandImage::FRAME_PRODUCT_PERSON, 900, 1200),
            $this->image('b.jpg', BrandImage::FRAME_PRODUCT_FLAT, 900, 1200),
            $this->image('z.jpg', BrandImage::FRAME_OTHER, 900, 1200),
        ]);

        $paths = $this->service()->paths($brand);

        self::assertSame(['/images/brands/a.jpg', '/images/brands/b.jpg'], $paths, 'other не берётся — без него уже MIN_SLIDES');
    }

    public function testOtherIsUsedToTopUpWhenCoreIsBelowMinSlides(): void
    {
        $brand = $this->brand([
            $this->image('a.jpg', BrandImage::FRAME_PRODUCT_PERSON, 900, 1200),
            $this->image('z.jpg', BrandImage::FRAME_OTHER, 900, 1200),
        ]);

        $paths = $this->service()->paths($brand);

        self::assertSame(['/images/brands/a.jpg', '/images/brands/z.jpg'], $paths, 'other добирает галерею до MIN_SLIDES, всегда в конце');
    }

    public function testUnclassifiedSitsBetweenProductGroupsAndScene(): void
    {
        $brand = $this->brand([
            $this->image('scene.jpg', BrandImage::FRAME_SCENE, 900, 1200),
            $this->image('null.jpg', null, 900, 1200),
            $this->image('flat.jpg', BrandImage::FRAME_PRODUCT_FLAT, 900, 1200),
            $this->image('person.jpg', BrandImage::FRAME_PRODUCT_PERSON, 900, 1200),
        ]);

        $paths = $this->service()->paths($brand);

        self::assertSame([
            '/images/brands/person.jpg',
            '/images/brands/flat.jpg',
            '/images/brands/null.jpg',
            '/images/brands/scene.jpg',
        ], $paths);
    }

    public function testTieBreakIsStableById(): void
    {
        // Три сцены без иной сортирующей характеристики — порядок должен идти по возрастанию id
        // независимо от порядка добавления в коллекцию бренда.
        $s2 = $this->image('s2.jpg', BrandImage::FRAME_SCENE, 900, 1200);
        $s1 = $this->image('s1.jpg', BrandImage::FRAME_SCENE, 900, 1200);
        $s3 = $this->image('s3.jpg', BrandImage::FRAME_SCENE, 900, 1200);
        $brand = $this->brand([$s2, $s1, $s3]);

        $paths = $this->service()->paths($brand);

        self::assertSame([
            '/images/brands/s2.jpg',
            '/images/brands/s1.jpg',
            '/images/brands/s3.jpg',
        ], $paths, 'id присваивались в порядке s2 < s1 < s3 — сортировка обязана это уважать');
    }

    private function service(): BrandGalleryImages
    {
        return new BrandGalleryImages($this->projectDir);
    }

    /** @param list<BrandImage> $images */
    private function brand(array $images): Brand
    {
        $brand = new Brand();
        foreach ($images as $image) {
            $brand->addImage($image);
        }

        return $brand;
    }

    private function image(string $file, ?string $frameKind, int $w, int $h): BrandImage
    {
        $this->makeImage("/public_html/images/brands/{$file}", $w, $h);

        $image = (new BrandImage())->setImage($file)->setFrameKind($frameKind);

        $ref = new \ReflectionProperty(BrandImage::class, 'id');
        $ref->setValue($image, $this->nextId++);

        return $image;
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
