<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Brand;
use App\Entity\SocialPost;

/**
 * Нормализация фото бренда в слайды одного формата + слайд с логотипом.
 *
 * Зачем нормализация: Instagram кадрирует ВСЮ карусель по пропорции первого слайда, а фото
 * брендов приходят произвольных размеров. Без приведения к одному холсту (а) часть слайдов
 * обрезалась бы, (б) A/B «логотип первым vs последним» сравнивал бы ещё и разное
 * кадрирование — эксперимент был бы грязным.
 *
 * Вписываем целиком с полями (contain), а не обрезаем (cover): у одежды обрез съедает вещь,
 * а это единственное, что в кадре важно. Холст 4:5 — максимум площади в ленте IG.
 */
class GallerySlideRenderer
{
    public const WIDTH  = 1080;
    public const HEIGHT = 1350;

    /** Логотип занимает эту долю ширины холста. */
    private const LOGO_WIDTH_RATIO = 0.62;

    private const TITLE_FONT_SIZE  = 52;
    private const FOOTER_FONT_SIZE = 28;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $fontPath,
    ) {
    }

    /**
     * Отрендерить слайды поста: нормализованные фото + слайд с логотипом в начале или конце.
     * Уже отрендеренные файлы переиспользуются (идемпотентно по id поста).
     *
     * @param list<string> $sources публичные пути исходных фото (/images/brands/...)
     *
     * @return list<string> публичные пути слайдов по порядку; [] если рендер невозможен
     */
    public function render(SocialPost $post, array $sources, bool $logoFirst): array
    {
        $dir = $this->projectDir . '/public_html' . $this->publicDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        $postId = (int) $post->getId();
        $slides = [];

        foreach ($sources as $i => $source) {
            $name = sprintf('p%d-%02d.jpg', $postId, $i + 1);
            if ($this->normalize($this->projectDir . '/public_html' . $source, $dir . '/' . $name)) {
                $slides[] = $this->publicDir() . '/' . $name;
            }
        }

        if ($slides === []) {
            return [];
        }

        $brand = $post->getBrand();
        if ($brand !== null) {
            $logoName = sprintf('p%d-logo.jpg', $postId);
            if ($this->logoSlide($brand, $dir . '/' . $logoName)) {
                $logoPath = $this->publicDir() . '/' . $logoName;
                if ($logoFirst) {
                    array_unshift($slides, $logoPath);
                } else {
                    $slides[] = $logoPath;
                }
            }
        }

        return $slides;
    }

    public function publicDir(): string
    {
        return '/images/social/gallery';
    }

    /** Вписать изображение в холст 1080×1350 на белом поле. */
    private function normalize(string $srcAbs, string $dstAbs): bool
    {
        if (is_file($dstAbs)) {
            return true;
        }
        if (!is_file($srcAbs)) {
            return false;
        }

        // Формат определяем по содержимому, а не по расширению: в корпусе брендов .jpg
        // иногда лежит webp/png (та же грабля, что в PublicMediaHost).
        $bytes = @file_get_contents($srcAbs);
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($src === false) {
            return false;
        }

        $canvas = $this->canvas();
        $this->drawContained($canvas, $src, 0, 0, self::WIDTH, self::HEIGHT);
        imagedestroy($src);

        $ok = imagejpeg($canvas, $dstAbs, 88);
        imagedestroy($canvas);

        return $ok;
    }

    /** Слайд-обложка: логотип бренда по центру + название и подпись движения. */
    private function logoSlide(Brand $brand, string $dstAbs): bool
    {
        if (is_file($dstAbs)) {
            return true;
        }

        $logo = $brand->getLogo();
        if ($logo === null || trim($logo) === '') {
            return false;
        }

        $logoAbs = $this->projectDir . '/public_html/images/logos/' . $logo;
        $bytes = is_file($logoAbs) ? @file_get_contents($logoAbs) : false;
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($src === false) {
            return false;
        }

        // Фон холста = фон самого логотипа (угловой пиксель): часть логотипов в корпусе —
        // растровые блоки со своей заливкой, и на белом они смотрелись бы серым прямоугольником
        // посреди слайда. Совпадение фонов делает слайд цельным.
        [$bgR, $bgG, $bgB] = $this->cornerColor($src);
        $canvas = $this->canvas($bgR, $bgG, $bgB);

        $boxWidth = (int) (self::WIDTH * self::LOGO_WIDTH_RATIO);
        $boxHeight = $boxWidth;
        $boxX = (int) ((self::WIDTH - $boxWidth) / 2);
        $boxY = (int) (self::HEIGHT * 0.22);
        $this->drawContained($canvas, $src, $boxX, $boxY, $boxWidth, $boxHeight);
        imagedestroy($src);

        if (is_file($this->fontPath)) {
            // Контраст к фону: на тёмном белый текст, на светлом — почти чёрный.
            $dark = (0.299 * $bgR + 0.587 * $bgG + 0.114 * $bgB) < 140;
            $ink = $dark
                ? imagecolorallocate($canvas, 255, 255, 255)
                : imagecolorallocate($canvas, 17, 24, 39);
            $muted = $dark
                ? imagecolorallocate($canvas, 209, 213, 219)
                : imagecolorallocate($canvas, 156, 163, 175);
            $this->centeredText($canvas, (string) $brand->getTitle(), self::TITLE_FONT_SIZE, (int) (self::HEIGHT * 0.78), $ink);
            $this->centeredText($canvas, 'WEARBASE · #ПрямойБренд', self::FOOTER_FONT_SIZE, self::HEIGHT - 80, $muted);
        }

        $ok = imagejpeg($canvas, $dstAbs, 90);
        imagedestroy($canvas);

        return $ok;
    }

    private function canvas(int $r = 255, int $g = 255, int $b = 255): \GdImage
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $fill = imagecolorallocate($canvas, $r, $g, $b);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $fill);

        return $canvas;
    }

    /**
     * Цвет фона логотипа по угловому пикселю. Прозрачный угол (PNG с альфой) — считаем белым:
     * такой логотип и рисуется на белом.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function cornerColor(\GdImage $src): array
    {
        $rgba = imagecolorat($src, 0, 0);
        $alpha = ($rgba >> 24) & 0x7F;
        if ($alpha > 64) {
            return [255, 255, 255];
        }

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    /** Вписать $src в прямоугольник, сохранив пропорции, по центру прямоугольника. */
    private function drawContained(\GdImage $canvas, \GdImage $src, int $x, int $y, int $boxW, int $boxH): void
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            return;
        }

        $scale = min($boxW / $srcW, $boxH / $srcH);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        imagecopyresampled(
            $canvas,
            $src,
            $x + (int) (($boxW - $dstW) / 2),
            $y + (int) (($boxH - $dstH) / 2),
            0,
            0,
            $dstW,
            $dstH,
            $srcW,
            $srcH,
        );
    }

    private function centeredText(\GdImage $canvas, string $text, int $size, int $baselineY, int $color): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $bbox = imagettfbbox($size, 0, $this->fontPath, $text);
        $x = (int) ((self::WIDTH - ($bbox[2] - $bbox[0])) / 2);
        imagettftext($canvas, $size, 0, $x, $baselineY, $color, $this->fontPath, $text);
    }
}
