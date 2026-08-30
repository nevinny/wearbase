<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\ProductImage;
use App\Twig\ProductImageExtension;
use PHPUnit\Framework\TestCase;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * product_image_url(): в БД у ProductImage голое имя файла, но физически файлы живут
 * в двух раскладках — плоской (импорт, ImageDownloaderService) и Vich-подкаталогах
 * (загрузка из ЛК, SubdirDirectoryNamer). Хелпер обязан отдавать рабочий URL для обеих.
 */
final class ProductImageExtensionTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/pie_test_' . uniqid();
        mkdir($this->publicDir . '/images/products', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicDir . '/images/products/flat.webp');
        @rmdir($this->publicDir . '/images/products');
        @rmdir($this->publicDir . '/images');
        @rmdir($this->publicDir);
    }

    private function makeExtension(?string $vichResult = null): ProductImageExtension
    {
        $vich = $this->createMock(StorageInterface::class);
        $vich->method('resolveUri')->willReturn($vichResult);

        return new ProductImageExtension($vich, $this->publicDir);
    }

    public function testFlatImportedFileResolvesFlat(): void
    {
        file_put_contents($this->publicDir . '/images/products/flat.webp', 'x');
        $image = (new ProductImage())->setPreview('flat.webp');

        $url = $this->makeExtension('/should/not/be/used')->productImageUrl($image);

        $this->assertSame('/images/products/flat.webp', $url);
    }

    public function testVichFileWithoutFlatCopyResolvesViaVich(): void
    {
        $image = (new ProductImage())->setPreview('ab12cd.webp');

        $url = $this->makeExtension('/images/products/ab/12/ab12cd.webp')->productImageUrl($image);

        $this->assertSame('/images/products/ab/12/ab12cd.webp', $url);
    }

    public function testPreviewPreferredOverImageByDefault(): void
    {
        file_put_contents($this->publicDir . '/images/products/flat.webp', 'x');
        $image = (new ProductImage())->setPreview('flat.webp')->setImage('orig.webp');

        $this->assertSame('/images/products/flat.webp', $this->makeExtension()->productImageUrl($image));
    }

    public function testPreferImageUsesImageField(): void
    {
        file_put_contents($this->publicDir . '/images/products/flat.webp', 'x');
        $image = (new ProductImage())->setPreview('prev.webp')->setImage('flat.webp');

        $this->assertSame(
            '/images/products/flat.webp',
            $this->makeExtension('/vich/prev')->productImageUrl($image, 'image'),
        );
    }

    public function testFallsBackToOtherFieldWhenPreferredEmpty(): void
    {
        file_put_contents($this->publicDir . '/images/products/flat.webp', 'x');
        $image = (new ProductImage())->setImage('flat.webp');

        $this->assertSame('/images/products/flat.webp', $this->makeExtension()->productImageUrl($image));
    }

    public function testNullWhenNoFilenames(): void
    {
        $this->assertNull($this->makeExtension()->productImageUrl(new ProductImage()));
        $this->assertNull($this->makeExtension()->productImageUrl(null));
    }
}
