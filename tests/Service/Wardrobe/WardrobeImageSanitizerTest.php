<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\Wardrobe\WardrobeImageSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageSanitizerTest extends TestCase
{
    private const RED = [255, 0, 0];

    #[DataProvider('exifOrientationProvider')]
    public function testRotatesPhotoByExifOrientation(int $orientation, int $expectedX, int $expectedY): void
    {
        $input = $this->writeJpegWithOrientation($orientation);
        self::assertSame($orientation, $this->readOrientation($input), 'Фикстура должна нести EXIF Orientation');

        try {
            $result = (new WardrobeImageSanitizer())->sanitize($this->uploaded($input));

            try {
                $image = imagecreatefromstring((string) file_get_contents($result->getPathname()));
                self::assertNotFalse($image, 'Санитизированный файл должен остаться валидным JPEG');
                $pixel = $this->pixelAt($image, $expectedX, $expectedY);
                self::assertTrue(
                    abs($pixel[0] - 255) < 60 && $pixel[1] < 100 && $pixel[2] < 100,
                    sprintf('После санитизации красный пиксель должен быть в (%d,%d), там %s', $expectedX, $expectedY, implode(',', $pixel)),
                );
                self::assertNull($this->readOrientation($result->getPathname()), 'EXIF Orientation должен быть стёрт при перекодировании');
            } finally {
                if (isset($image) && $image !== false) {
                    imagedestroy($image);
                }
            }
        } finally {
            @unlink($input);
        }
    }

    public static function exifOrientationProvider(): array
    {
        // imagerotate против часовой: 6 → -90°, 8 → 90°, 3 → 180°
        return [
            'orientation 6 (повернуть на -90)' => [6, 1, 0],
            'orientation 8 (повернуть на 90)' => [8, 0, 1],
            'orientation 3 (переворот 180)' => [3, 1, 1],
        ];
    }

    public function testWithoutExifKeepsPixelsUnchanged(): void
    {
        $input = $this->writeJpegWithOrientation(null);

        try {
            $result = (new WardrobeImageSanitizer())->sanitize($this->uploaded($input));

            $image = imagecreatefromstring((string) file_get_contents($result->getPathname()));
            self::assertNotFalse($image);
            try {
                self::assertSame(
                    true,
                    $this->isRedish($this->pixelAt($image, 0, 0)),
                    'Без EXIF фото должно пройти насквозь без поворота',
                );
            } finally {
                imagedestroy($image);
            }
        } finally {
            @unlink($input);
        }
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    private function readOrientation(string $path): ?int
    {
        $exif = @exif_read_data($path);

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
    }

    /**
     * Крошечный JPEG 2x2 с красным пикселем в левом верхнем углу,
     * при необходимости — с APP1-сегментом EXIF Orientation сразу после SOI.
     */
    private function writeJpegWithOrientation(?int $orientation): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagesetpixel($image, 0, 0, imagecolorallocate($image, ...self::RED));
        $tmp = tempnam(sys_get_temp_dir(), 'wardrobe_fix_');
        self::assertNotFalse($tmp);
        imagejpeg($image, $tmp, 92);
        imagedestroy($image);

        $bytes = (string) file_get_contents($tmp);
        if ($orientation !== null) {
            $bytes = substr_replace($bytes, $this->app1Segment($orientation), 2, 0);
            file_put_contents($tmp, $bytes);
        }

        return $tmp;
    }

    private function app1Segment(int $orientation): string
    {
        // TIFF little-endian: II, magic 42, IFD0 @ 8
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)                                   // одна запись IFD
            .pack('v', 0x0112).pack('v', 3).pack('V', 1)    // tag Orientation, type SHORT, count 1
            .pack('vv', $orientation, 0)                    // значение + паддинг до 4 байт
            .pack('V', 0);                                  // next IFD = 0

        return "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff)).'Exif'."\x00\x00".$tiff;
    }

    /**
     * @return int[] RGB
     */
    private function pixelAt(\GdImage $image, int $x, int $y): array
    {
        return array_values(array_slice(imagecolorsforindex($image, imagecolorat($image, $x, $y)), 0, 3));
    }

    /**
     * @param int[] $rgb
     */
    private function isRedish(array $rgb): bool
    {
        return abs($rgb[0] - 255) < 60 && $rgb[1] < 100 && $rgb[2] < 100;
    }
}
