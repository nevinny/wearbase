<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\Wardrobe\WardrobeImageSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageSanitizerTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $tmpFiles = [];

    public static function orientedJpegProvider(): \Generator
    {
        yield 'orientation 6' => [6];
        yield 'orientation 8' => [8];
        yield 'orientation 3' => [3];
    }

    #[DataProvider('orientedJpegProvider')]
    public function testAppliesExifOrientationToJpeg(int $orientation): void
    {
        if (!\function_exists('exif_read_data')) {
            self::markTestSkipped('ext-exif недоступен');
        }

        $source = $this->writeJpegWithOrientation($orientation);

        $sanitized = (new WardrobeImageSanitizer())->sanitize(new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true));
        $this->tmpFiles[] = $sanitized->getPathname();

        [$expectedWidth, $expectedHeight] = match ($orientation) {
            3       => [2, 1],
            6, 8    => [1, 2],
        };
        $gd = imagecreatefromstring((string) file_get_contents($sanitized->getPathname()));
        self::assertNotFalse($gd);
        self::assertSame([$expectedWidth, $expectedHeight], [imagesx($gd), imagesy($gd)], "Ориентация $orientation не применена");

        // 2x1: слева красный, справа синий. После коррекции цвета должны занять ожидаемые позиции.
        if ($expectedWidth === 2) {
            $this->assertPixelDominant($gd, 0, 0, $orientation === 3 ? 'blue' : 'red');
            $this->assertPixelDominant($gd, 1, 0, $orientation === 3 ? 'red' : 'blue');
        } else {
            [$topColor, $bottomColor] = $orientation === 6 ? ['red', 'blue'] : ['blue', 'red'];
            $this->assertPixelDominant($gd, 0, 0, $topColor);
            $this->assertPixelDominant($gd, 0, 1, $bottomColor);
        }
    }

    public function testKeepsUprightJpegUntouched(): void
    {
        $source = $this->writeJpegWithOrientation(1);

        $sanitized = (new WardrobeImageSanitizer())->sanitize(new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true));
        $this->tmpFiles[] = $sanitized->getPathname();

        $gd = imagecreatefromstring((string) file_get_contents($sanitized->getPathname()));
        self::assertNotFalse($gd);
        self::assertSame([2, 1], [imagesx($gd), imagesy($gd)]);
        $this->assertPixelDominant($gd, 0, 0, 'red');
        $this->assertPixelDominant($gd, 1, 0, 'blue');
    }

    public function testKeepsJpegWithoutExifUntouched(): void
    {
        $source = $this->writeJpegWithOrientation(null);

        $sanitized = (new WardrobeImageSanitizer())->sanitize(new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true));
        $this->tmpFiles[] = $sanitized->getPathname();

        $gd = imagecreatefromstring((string) file_get_contents($sanitized->getPathname()));
        self::assertNotFalse($gd);
        self::assertSame([2, 1], [imagesx($gd), imagesy($gd)]);
        $this->assertPixelDominant($gd, 0, 0, 'red');
        $this->assertPixelDominant($gd, 1, 0, 'blue');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        $this->tmpFiles = [];
    }

    /**
     * Реальный JPEG через GD (2x1: красный/синий) + вручную собранный APP1 EXIF-сегмент
     * с единственным IFD0-entry — тег 0x0112 Orientation (тип SHORT).
     */
    private function writeJpegWithOrientation(?int $orientation): string
    {
        $img = imagecreatetruecolor(2, 1);
        self::assertNotFalse($img);
        $red = (int) imagecolorallocate($img, 255, 0, 0);
        $blue = (int) imagecolorallocate($img, 0, 0, 255);
        imagesetpixel($img, 0, 0, $red);
        imagesetpixel($img, 1, 0, $blue);
        ob_start();
        imagejpeg($img, null, 95);
        imagedestroy($img);
        $jpeg = (string) ob_get_clean();

        if ($orientation !== null) {
            $tiff = 'II'
                .pack('v', 42)
                .pack('V', 8)
                .pack('v', 1)
                .pack('v', 0x0112)      // тег Orientation
                .pack('v', 3)           // тип SHORT
                .pack('V', 1)
                .pack('v', $orientation)."\x00\x00"
                .pack('V', 0);
            $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;
            $jpeg = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'wardrobe_exif_');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $jpeg));
        $this->tmpFiles[] = $path;

        // Самопроверка фикстуры: EXIF действительно читается и содержит нужную ориентацию
        if ($orientation !== null && \function_exists('exif_read_data')) {
            $exif = exif_read_data($path);
            self::assertIsArray($exif);
            self::assertSame($orientation, $exif['Orientation']);
        }

        return $path;
    }

    private function assertPixelDominant(\GdImage $gd, int $x, int $y, string $channel): void
    {
        $color = imagecolorat($gd, $x, $y);
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        $dominant = $r > $g && $r > $b ? 'red' : ($b > $r && $b > $g ? 'blue' : 'other');
        self::assertSame($channel, $dominant, sprintf('Пиксель (%d,%d): ожидался %s, получен rgb(%d,%d,%d)', $x, $y, $channel, $r, $g, $b));
    }
}
