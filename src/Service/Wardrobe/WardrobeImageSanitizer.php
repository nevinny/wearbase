<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageSanitizer
{
    public function sanitize(UploadedFile $file): UploadedFile
    {
        $raw = file_get_contents($file->getPathname());
        $image = is_string($raw) ? @imagecreatefromstring($raw) : false;
        if ($image === false) {
            throw new \InvalidArgumentException('Не удалось безопасно обработать изображение');
        }
        $image = $this->applyExifOrientation($image, $file->getPathname());
        $path = tempnam(sys_get_temp_dir(), 'wardrobe_clean_');
        if ($path === false || !imagejpeg($image, $path, 90)) {
            imagedestroy($image);
            throw new \RuntimeException('Не удалось очистить фото');
        }
        imagedestroy($image);
        return new UploadedFile($path, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', 'image/jpeg', null, true);
    }

    /**
     * Телефонные JPEG лежат на боку с EXIF Orientation — физически поворачиваем
     * пиксели (та же семантика углов, что в LlmService::downscaleImage()).
     */
    private function applyExifOrientation(\GdImage $image, string $sourcePath): \GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;
        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => false,
        };
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
