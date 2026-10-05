<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Service\Wardrobe\PreparedWardrobePhoto;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use App\Service\Wardrobe\WardrobeImageVariants;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageVariantsTest extends TestCase
{
    private string $directory;
    private WardrobeImageVariants $variants;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/wardrobe-variants-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->variants = new WardrobeImageVariants($this->directory, new WardrobeImageSanitizer());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testWebVariantsAreBoundedAndOriginalRemainsByteIdentical(): void
    {
        $source = $this->jpeg(2400, 1600);
        $hash = hash_file('sha256', $source);
        $upload = new UploadedFile($source, 'original.jpg', 'image/jpeg', null, true);
        $clean = (new WardrobeImageSanitizer())->preserveOriginal($upload);
        try {
            self::assertSame([2400, 1600], array_slice(getimagesize($clean->getPathname()), 0, 2));
        } finally {
            unlink($clean->getPathname());
        }
        foreach (['preview' => [320, 213], 'medium' => [1280, 853]] as $size => $dimensions) {
            $path = $this->variants->path($source, $size);
            self::assertSame($dimensions, array_slice(getimagesize($path), 0, 2));
            self::assertSame('image/webp', mime_content_type($path));
            self::assertLessThan(filesize($source), filesize($path));
            self::assertSame($path, $this->variants->path($source, $size));
        }
        self::assertSame($hash, hash_file('sha256', $source));
    }

    public function testOriginalIsNotAnAvailableVariant(): void
    {
        $source = $this->jpeg(80, 40);
        $this->expectException(\InvalidArgumentException::class);
        $this->variants->path($source, 'original');
    }

    public function testExifAndManualRotationComposeWithoutUpscalingOrOverwritingSource(): void
    {
        $source = $this->jpeg(80, 40);
        // JPEG APP1 with TIFF Orientation=6: the phone stores a landscape frame as portrait.
        $tiff = 'II'.pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
        $app1 = "\xFF\xE1".pack('n', 8 + strlen($tiff))."Exif\0\0".$tiff;
        file_put_contents($source, substr_replace(file_get_contents($source), $app1, 2, 0));
        $hash = hash_file('sha256', $source);
        $portrait = $this->variants->path($source, 'preview');
        self::assertSame([40, 80], array_slice(getimagesize($portrait), 0, 2));
        $rotated = $this->variants->path($source, 'preview', 90);
        self::assertNotSame($portrait, $rotated);
        self::assertSame([80, 40], array_slice(getimagesize($rotated), 0, 2));
        self::assertSame($hash, hash_file('sha256', $source));
        self::assertStringNotContainsString("Exif\0\0", file_get_contents($rotated));
    }

    public function testTransparentPalettePngRemainsTransparent(): void
    {
        $source = $this->directory.'/palette.png';
        $image = imagecreate(40, 80);
        $transparent = imagecolorallocate($image, 0, 0, 0);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, 10, 10, 30, 60, imagecolorallocate($image, 255, 0, 0));
        imagepng($image, $source);
        imagedestroy($image);
        $result = imagecreatefromwebp($this->variants->path($source, 'medium', 90));
        self::assertSame(127, imagecolorsforindex($result, imagecolorat($result, 0, 0))['alpha']);
        imagedestroy($result);
    }

    public function testRotationInvalidatesPreparedCutoutAndReturningToOriginalRestoresRevision(): void
    {
        $item = new WardrobeItem();
        $photo = (new WardrobeItemPhoto())->setFilePath('original.jpg')->setIsCover(true);
        $item->addPhoto($photo);
        $revision = PreparedWardrobePhoto::revision($item);
        $photo->rotate(90);
        self::assertNotSame($revision, PreparedWardrobePhoto::revision($item));
        $photo->rotate(-90);
        self::assertSame($revision, PreparedWardrobePhoto::revision($item));
    }

    private function jpeg(int $width, int $height): string
    {
        $path = $this->directory.'/source.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 30, 20));
        imagejpeg($image, $path, 95);
        imagedestroy($image);
        return $path;
    }
}
