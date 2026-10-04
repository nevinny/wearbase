<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WardrobeImageVariants
{
    private const SIZES = ['preview' => 320, 'medium' => 1280];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly WardrobeImageSanitizer $sanitizer,
    ) {}

    public function path(string $source, string $variant, int $rotation = 0): string
    {
        if ($variant === 'original') {
            return $source;
        }
        if (!isset(self::SIZES[$variant]) || !in_array($rotation, [0, 90, 180, 270], true)) {
            throw new \InvalidArgumentException('Неизвестный размер или поворот фото');
        }
        $sourceHash = hash_file('sha256', $source);
        if ($sourceHash === false) {
            throw new \RuntimeException('Файл фото не найден');
        }
        $revision = hash('sha256', $sourceHash.':'.$variant.':'.$rotation.':webp82-v1');
        $directory = $this->projectDir.'/var/uploads/wardrobe_variants/'.substr($revision, 0, 2);
        $path = $directory.'/'.$revision.'.webp';
        if (is_file($path)) {
            return $path;
        }
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось подготовить фото');
        }
        $image = $this->sanitizer->orientedImage($source);
        $temporary = null;
        $resized = null;
        try {
            if ($rotation !== 0) {
                $rotated = imagerotate($image, -$rotation, imagecolorallocatealpha($image, 0, 0, 0, 127));
                if ($rotated === false) {
                    throw new \RuntimeException('Не удалось повернуть фото');
                }
                imagedestroy($image);
                $image = $rotated;
            }
            $scale = min(1, self::SIZES[$variant] / max(imagesx($image), imagesy($image)));
            $width = max(1, (int) round(imagesx($image) * $scale));
            $height = max(1, (int) round(imagesy($image) * $scale));
            $resized = imagecreatetruecolor($width, $height);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            $temporary = tempnam($directory, '.variant-');
            if ($temporary === false || !imagewebp($resized, $temporary, 82) || !rename($temporary, $path)) {
                throw new \RuntimeException('Не удалось подготовить фото');
            }
            chmod($path, 0640);

            return $path;
        } finally {
            imagedestroy($image);
            if ($resized instanceof \GdImage) {
                imagedestroy($resized);
            }
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
