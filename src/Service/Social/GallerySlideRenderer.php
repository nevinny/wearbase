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

    /** Суффикс имени слайда с логотипом — по нему отличаем его от фотографий. */
    private const LOGO_SUFFIX = '-logo.jpg';

    /** Суффикс слайда с хук-надписью (первый кадр). */
    private const HOOK_SUFFIX = '-hook.jpg';

    private const TITLE_FONT_SIZE  = 52;
    private const FOOTER_FONT_SIZE = 28;
    private const HOOK_FONT_SIZE   = 54;
    private const HOOK_LINE_HEIGHT = 70;
    private const COUNTER_FONT_SIZE = 34;

    /**
     * Безопасная зона Reels: слайд 4:5 вписывается в кадр 1920 со сдвигом (1920-1350)/2,
     * а Instagram рисует подпись/кнопки/аудио в нижних ~400 px кадра и держит верхнюю полосу.
     * Пересчёт в координаты слайда: всё, что ниже BOTTOM_LIMIT_Y, зритель не увидит.
     */
    private const FRAME_HEIGHT = 1920;
    private const FRAME_BOTTOM_UI = 420;
    private const FRAME_TOP_UI = 300;

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
    public function render(SocialPost $post, array $sources, bool $logoFirst, ?string $hook = null): array
    {
        $dir = $this->projectDir . '/public_html' . $this->publicDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        $postId = (int) $post->getId();
        $slides = [];

        // Счётчик «3/10» — приём списка: каждый непоказанный слайд даёт причину остаться.
        // Логотип тоже слайд, поэтому в total он входит, а нумерация зависит от ветки A/B.
        $total = count($sources) + 1;
        $photoShift = $logoFirst ? 1 : 0;
        foreach ($sources as $i => $source) {
            $name = sprintf('p%d-%02d.jpg', $postId, $i + 1);
            if ($this->normalize($this->projectDir . '/public_html' . $source, $dir . '/' . $name, $i + 1 + $photoShift, $total)) {
                $slides[] = $this->publicDir() . '/' . $name;
            }
        }

        if ($slides === []) {
            return [];
        }

        $hasHook = $hook !== null && trim($hook) !== '';

        $brand = $post->getBrand();
        if ($brand !== null) {
            $logoName = 'p' . $postId . self::LOGO_SUFFIX;
            // Если логотип идёт первым, хук обязан быть на НЁМ — но плашка поверх обычной
            // раскладки накрывала сам логотип, поэтому слайд рисуется в компактном варианте.
            $logoHook = $logoFirst && $hasHook ? $hook : null;
            if ($this->logoSlide($brand, $dir . '/' . $logoName, $logoFirst ? 1 : $total, $total, $logoHook)) {
                $logoPath = $this->publicDir() . '/' . $logoName;
                if ($logoFirst) {
                    array_unshift($slides, $logoPath);
                } else {
                    $slides[] = $logoPath;
                }
            }
        }

        // Хук-надпись — на ПЕРВОМ слайде последовательности, каким бы он ни был: первые
        // ~1.5 секунды решают раздачу Reels, и текст должен быть там в обеих ветках A/B.
        // Различие между ветками остаётся ровно одно — что за фон под хуком.
        if ($hasHook && !$logoFirst && $slides !== []) {
            $hookName = 'p' . $postId . self::HOOK_SUFFIX;
            if ($this->hookSlide($dir . '/' . basename($slides[0]), $dir . '/' . $hookName, $hook)) {
                $slides[0] = $this->publicDir() . '/' . $hookName;
            }
        }

        return $slides;
    }

    public function publicDir(): string
    {
        return '/images/social/gallery';
    }

    /**
     * Обложка Reels — ПЕРВОЕ нормализованное фото поста (p{id}-01.jpg).
     *
     * Именно фиксированный файл, а не «первый элемент списка»: в ветке logo_first первым идёт
     * карточка логотипа, в logo_last — кадр с хуком, и обложки у ветвей разъехались бы. Файл
     * лежит на диске в обеих ветках, даже когда в списке слайдов его подменил хук.
     */
    public function coverSlide(SocialPost $post): ?string
    {
        $public = sprintf('%s/p%d-01.jpg', $this->publicDir(), (int) $post->getId());

        return is_file($this->projectDir . '/public_html' . $public) ? $public : null;
    }

    /** Вписать изображение в холст 1080×1350 на белом поле + счётчик слайдов. */
    private function normalize(string $srcAbs, string $dstAbs, int $index, int $total): bool
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
        $this->drawCounter($canvas, $index, $total);

        $ok = imagejpeg($canvas, $dstAbs, 88);
        imagedestroy($canvas);

        return $ok;
    }

    /** Слайд-обложка: логотип бренда по центру + название и подпись движения. */
    private function logoSlide(Brand $brand, string $dstAbs, int $index, int $total, ?string $hook = null): bool
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

        // С хуком логотип уезжает выше и уменьшается, освобождая середину под текст.
        $ratio = $hook !== null ? 0.46 : self::LOGO_WIDTH_RATIO;
        $boxWidth = (int) (self::WIDTH * $ratio);
        $boxHeight = $boxWidth;
        $boxX = (int) ((self::WIDTH - $boxWidth) / 2);
        $boxY = (int) (self::HEIGHT * ($hook !== null ? 0.10 : 0.22));
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
            $titleY = (int) (self::HEIGHT * ($hook !== null ? 0.66 : 0.78));
            $this->centeredText($canvas, (string) $brand->getTitle(), self::TITLE_FONT_SIZE, $titleY, $ink);
            if ($hook !== null) {
                $this->drawHookBand($canvas, $hook, (int) (self::HEIGHT * 0.80));
            }
            // Футер — выше границы безопасной зоны: на HEIGHT-80 он оказывался в 365 px от низа
            // кадра Reels, то есть под подписью и кнопками Instagram.
            $this->centeredText($canvas, 'WEARBASE · #ПрямойБренд', self::FOOTER_FONT_SIZE, $this->bottomLimitY(), $muted);
        }

        $this->drawCounter($canvas, $index, $total);

        $ok = imagejpeg($canvas, $dstAbs, 90);
        imagedestroy($canvas);

        return $ok;
    }

    /**
     * Хук-надпись поверх готового слайда: тёмная подложка + крупный белый текст по центру
     * кадра. Отдельный файл, чтобы исходный слайд остался чистым (он же второй в другой ветке).
     */
    private function hookSlide(string $srcSlideAbs, string $dstAbs, string $hook): bool
    {
        if (is_file($dstAbs)) {
            return true;
        }
        if (!is_file($srcSlideAbs) || !is_file($this->fontPath)) {
            return false;
        }

        $bytes = @file_get_contents($srcSlideAbs);
        $canvas = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($canvas === false) {
            return false;
        }

        $this->drawHookBand($canvas, $hook, (int) (self::HEIGHT / 2));

        $ok = imagejpeg($canvas, $dstAbs, 90);
        imagedestroy($canvas);

        return $ok;
    }

    /**
     * Плашка с хук-надписью: тёмная подложка на всю ширину + белый текст по центру.
     * ≤26 символов в строке при кегле 54 и максимум ДВЕ строки: слайд живёт 1.5 секунды,
     * третью строку клиповый зритель прочитать не успеет.
     */
    private function drawHookBand(\GdImage $canvas, string $hook, int $centerY): void
    {
        $lines = array_slice($this->wrapByChars($hook, 26), 0, 2);
        if ($lines === []) {
            return;
        }

        $blockHeight = count($lines) * self::HOOK_LINE_HEIGHT;
        $top = $centerY - (int) ($blockHeight / 2);

        $scrim = imagecolorallocatealpha($canvas, 17, 24, 39, 40);
        imagefilledrectangle($canvas, 0, $top - 40, self::WIDTH - 1, $top + $blockHeight + 24, $scrim);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $y = $top + self::HOOK_FONT_SIZE;
        foreach ($lines as $line) {
            $this->centeredText($canvas, $line, self::HOOK_FONT_SIZE, $y, $white);
            $y += self::HOOK_LINE_HEIGHT;
        }
    }

    /** Счётчик «3/10» слева сверху — вне зон, перекрытых интерфейсом Reels. */
    private function drawCounter(\GdImage $canvas, int $index, int $total): void
    {
        if (!is_file($this->fontPath) || $total < 2) {
            return;
        }

        $label = $index . '/' . $total;
        $bbox = imagettfbbox(self::COUNTER_FONT_SIZE, 0, $this->fontPath, $label);
        $textWidth = $bbox[2] - $bbox[0];

        $padX = 26;
        $padY = 18;
        $x = 64;
        $baseline = 160;

        $pill = imagecolorallocatealpha($canvas, 17, 24, 39, 45);
        imagefilledrectangle(
            $canvas,
            $x - $padX,
            $baseline - self::COUNTER_FONT_SIZE - $padY,
            $x + $textWidth + $padX,
            $baseline + $padY,
            $pill,
        );

        imagettftext($canvas, self::COUNTER_FONT_SIZE, 0, $x, $baseline, imagecolorallocate($canvas, 255, 255, 255), $this->fontPath, $label);

        $this->drawProgress($canvas, $index, $total);
    }

    /**
     * Полоса прогресса сверху: сколько клипа осталось. Визуальный незакрытый гештальт —
     * то же, что счётчик, но читается без чтения, за долю секунды.
     * Y=40 в координатах слайда даёт 325 в кадре Reels, то есть ниже верхней полосы UI.
     */
    private function drawProgress(\GdImage $canvas, int $index, int $total): void
    {
        $margin = 64;
        $y = 40;
        $height = 8;
        $trackWidth = self::WIDTH - 2 * $margin;

        // Дорожка тёмная, заливка белая: белым по белому полоса пропадала на светлых слайдах,
        // а тёмная дорожка читается и на светлом фото, и на тёмном.
        $track = imagecolorallocatealpha($canvas, 17, 24, 39, 70);
        imagefilledrectangle($canvas, $margin, $y, $margin + $trackWidth, $y + $height, $track);

        $filled = (int) round($trackWidth * min(1.0, $index / max(1, $total)));
        $bar = imagecolorallocatealpha($canvas, 255, 255, 255, 10);
        imagefilledrectangle($canvas, $margin, $y, $margin + $filled, $y + $height, $bar);
    }

    /**
     * Нижняя граница видимого текста в координатах слайда: у Reels нижние FRAME_BOTTOM_UI
     * пикселей кадра заняты подписью, ником, аудио и кнопками.
     */
    private function bottomLimitY(): int
    {
        $offset = (int) ((self::FRAME_HEIGHT - self::HEIGHT) / 2);

        return self::FRAME_HEIGHT - self::FRAME_BOTTOM_UI - $offset;
    }

    /** @return list<string> */
    private function wrapByChars(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
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
