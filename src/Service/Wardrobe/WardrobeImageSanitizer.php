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
        $path = tempnam(sys_get_temp_dir(), 'wardrobe_clean_');
        if ($path === false || !imagejpeg($image, $path, 90)) {
            imagedestroy($image);
            throw new \RuntimeException('Не удалось очистить фото');
        }
        imagedestroy($image);
        return new UploadedFile($path, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', 'image/jpeg', null, true);
    }
}
