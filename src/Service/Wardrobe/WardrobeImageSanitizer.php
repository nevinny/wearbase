<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeImageSanitizer
{
    /**
     * Пересобираем JPEG через GD, поэтому весь EXIF (включая Orientation) теряется:
     * читаем ориентацию ДО пересборки и физически доворачиваем пиксели — иначе
     * телефонные снимки ложатся на бок. Та же карта поворотов, что в LlmService::downscaleImage.
     */
    public function sanitize(UploadedFile $file): UploadedFile
    {
        $path = $file->getPathname();
        $raw = file_get_contents($path);
        $image = is_string($raw) ? @imagecreatefromstring($raw) : false;
        if ($image === false) {
            throw new \InvalidArgumentException('Не удалось безопасно обработать изображение');
        }

        if (\function_exists('exif_read_data') && str_starts_with($raw, "\xFF\xD8")) {
            $exif = @exif_read_data($path);
            $image = $this->applyExifOrientation($image, is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1);
        }

        $cleanPath = tempnam(sys_get_temp_dir(), 'wardrobe_clean_');
        if ($cleanPath === false || !imagejpeg($image, $cleanPath, 90)) {
            imagedestroy($image);
            throw new \RuntimeException('Не удалось очистить фото');
        }
        imagedestroy($image);

        return new UploadedFile($cleanPath, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', 'image/jpeg', null, true);
    }

    /**
     * EXIF Orientation: каким должен быть результат на экране относительно лежащих
     * в файле пикселей. Угол imagerotate — против часовой, поэтому 90° по часовой = -90.
     */
    private function applyExifOrientation(\GdImage $src, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2: // зеркало по горизонтали
                imageflip($src, IMG_FLIP_HORIZONTAL);

                return $src;
            case 3: // переворот на 180°
                $rotated = imagerotate($src, 180, 0);
                break;
            case 4: // зеркало по вертикали
                imageflip($src, IMG_FLIP_VERTICAL);

                return $src;
            case 5: // transpose: 90° по часовой + зеркало по горизонтали
            case 7: // transverse: 90° по часовой + зеркало по вертикали
                $rotated = imagerotate($src, -90, 0);
                if ($rotated !== false) {
                    imageflip($rotated, $orientation === 5 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
                }
                break;
            case 6: // доворот на 90° по часовой
                $rotated = imagerotate($src, -90, 0);
                break;
            case 8: // доворот на 90° против часовой
                $rotated = imagerotate($src, 90, 0);
                break;
            default: // 1 и неизвестные значения — без изменений
                return $src;
        }

        if ($rotated === false) {
            return $src;
        }
        imagedestroy($src);

        return $rotated;
    }
}
