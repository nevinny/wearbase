<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageSanitizer
{
    public function sanitize(UploadedFile $file): UploadedFile
    {
        $image = $this->orientedImage($file->getPathname(), 1280);
        $path = tempnam(sys_get_temp_dir(), 'wardrobe_clean_');
        if ($path === false || !imagejpeg($image, $path, 90)) {
            imagedestroy($image);
            throw new \RuntimeException('Не удалось очистить фото');
        }
        imagedestroy($image);
        return new UploadedFile($path, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', 'image/jpeg', null, true);
    }

    /** Strip metadata at full resolution, baking EXIF orientation into the stored pixels. */
    public function preserveOriginal(UploadedFile $file): UploadedFile
    {
        $image = $this->orientedImage($file->getPathname());
        $path = tempnam(sys_get_temp_dir(), 'wardrobe_original_');
        $saved = false;
        try {
            if ($path === false) {
                throw new \RuntimeException('Не удалось очистить фото');
            }
            imagepalettetotruecolor($image);
            imagesavealpha($image, true);
            [$extension, $mime] = match ($file->getMimeType()) {
                'image/png' => ['png', 'image/png'],
                'image/webp' => ['webp', 'image/webp'],
                default => ['jpg', 'image/jpeg'],
            };
            $saved = match ($extension) {
                'png' => imagepng($image, $path),
                'webp' => imagewebp($image, $path, 90),
                default => imagejpeg($image, $path, 95),
            };
            if (!$saved) {
                throw new \RuntimeException('Не удалось очистить фото');
            }

            return new UploadedFile($path, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$extension, $mime, null, true);
        } finally {
            imagedestroy($image);
            if (!$saved && is_string($path)) {
                unlink($path);
            }
        }
    }

    public function orientedImage(string $path, ?int $maxSide = null): \GdImage
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] * $size[1] > 25_000_000) {
            throw new \InvalidArgumentException('Не удалось безопасно обработать изображение');
        }
        $image = match ($size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if ($image === false) {
            throw new \InvalidArgumentException('Не удалось безопасно обработать изображение');
        }

        // Shrink before rotating: a phone photo must not allocate two full-size canvases.
        if ($maxSide !== null && max($size[0], $size[1]) > $maxSide) {
            $scale = $maxSide / max($size[0], $size[1]);
            $resized = imagecreatetruecolor(max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale)));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $size[0], $size[1]);
            imagedestroy($image);
            $image = $resized;
        }

        return $this->applyExifOrientation($image, $path);
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
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            5       => imagerotate($image, 90, 0),
            6       => imagerotate($image, -90, 0),
            7       => imagerotate($image, -90, 0),
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
