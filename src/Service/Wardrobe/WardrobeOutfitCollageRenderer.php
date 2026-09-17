<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Коллаж образа «на утро» — одна картинка вместо списка вещей с подписями (владелец должен
 * понять образ одним взглядом с телефона). Рендерится РАЗ, в момент, когда ночной пакетный
 * конвейер кладёт образ на прод (см. вызов из WardrobeDailyController::outfits(), сразу после
 * flush — там уже известен id), а не на лету при открытии /account/wardrobe/outfits.
 * Идемпотентно по id образа (is_file), как GallerySlideRenderer/LookShareOgCardRenderer.
 *
 * Приватность: фото гардероба не публичные (см. MigrateWardrobeMediaToPrivateStorageCommand),
 * поэтому коллаж лежит рядом с ними — в var/uploads/wardrobe_outfits (НЕ public_html) — и
 * раздаётся авторизованным маршрутом (WardrobeMediaController::outfit(), тот же FamilyService).
 *
 * Заголовок: сверху — повод (WardrobeOutfit::DAILY_OCCASIONS), картинка самодостаточна и
 * читается отдельно от страницы (открыв несколько подряд, видно, где «на работу», где «в театр»).
 *
 * Раскладка: вещи делятся на «компактные» (обувь/сумки/аксессуары) и «высокие» (всё остальное —
 * платья, верх, низ, верхняя одежда). Высокие идут в сетку сверху (не больше 2 колонок в ряду,
 * лишние — новым рядом, см. grid()), компактные — отдельной строкой снизу. Каждая ячейка
 * заполняется фото ЦЕЛИКОМ (cover-кроп, без полей внутри ячейки) — холст обязан быть плотным,
 * без пустых полей: это карточка, которую смотрят пять секунд спросонья, а не список фото с
 * подписями. Для «высоких» вещей кроп смещён к верхней части кадра (TALL_ANCHOR_Y) — у фото в
 * полный рост низ занимает пол/асфальт, и центр-кроп срезал бы саму вещь сильнее, чем верх.
 */
final class WardrobeOutfitCollageRenderer
{
    // Публичные (как в GallerySlideRenderer) — тест сверяет размер готового холста.
    public const WIDTH = 1080;
    public const HEIGHT = 1350;
    private const MARGIN = 24;
    private const GAP = 16;

    /** Заголовок повода сверху — своя полоса высоты, в сетку вещей не входит. */
    private const HEADER_HEIGHT = 100;
    private const HEADER_FONT_SIZE = 44;

    /** Доля высоты рабочей области под строку компактных вещей (обувь/сумки). */
    private const STRIP_HEIGHT_RATIO = 0.26;

    /**
     * Вертикальный якорь cover-кропа (0 = верх кадра, 1 = низ; 0.5 = центр). «Высокие» вещи
     * снимают в полный рост — низ кадра почти всегда пол/асфальт, поэтому окно кропа сдвинуто
     * к верхней трети, а не к центру (см. докблок класса). У компактных (обувь/сумки) кадр обычно
     * уже плотно скомпонован — центр им не вредит.
     */
    private const TALL_ANCHOR_Y = 0.2;
    private const COMPACT_ANCHOR_Y = 0.5;

    /** Кегль плейсхолдера — коллаж открывают с телефона внутри карточки (~0.3 масштаба холста), мельче нечитаемо. */
    private const NAME_FONT_SIZE = 52;
    private const META_FONT_SIZE = 36;
    private const LINE_HEIGHT_RATIO = 1.25;

    /**
     * Разрешённая площадь фото ПЕРЕД декодированием (getimagesize) — иначе кадр декодируется в
     * truecolor GD (4 байта/px) и может выбить memory_limit веб-запроса (агент-API — обычный
     * HTTP-запрос прода, PHP fatal на OOM не ловится try/catch в WardrobeDailyController::outfits()).
     * 12M px ≈ 48 МБ в GD — тот же порядок, что WardrobeImageSanitizer уже декодирует при
     * загрузке фото (проверено, что влезает); 24M+ (типичный кадр телефона в высоком разрешении
     * без сжатия) уже не гарантирован.
     */
    private const MAX_SOURCE_PIXELS = 12_000_000;

    /** Ключи категорий с горизонтальными фото (обувь, сумки, ремни/шапки/шарфы и т.п.). */
    private const COMPACT_KEYWORDS = [
        'туфл', 'ботильон', 'ботинк', 'сапог', 'кроссовк', 'сандал', 'шлёпан', 'кед', 'мокасин', 'лофер', 'обувь',
        'сумк', 'рюкзак', 'клатч', 'ремен', 'шапк', 'шарф', 'очки', 'украшен', 'пояс', 'перчат', 'кепк', 'шляп',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $fontPath,
        private readonly StorageInterface $storage,
    ) {
    }

    /** Абсолютный путь коллажа образа — детерминирован по id, отдельная таблица/поле не нужны. */
    public function path(int $outfitId): string
    {
        return $this->projectDir . '/var/uploads/wardrobe_outfits/o' . $outfitId . '.jpg';
    }

    public function exists(int $outfitId): bool
    {
        return is_file($this->path($outfitId));
    }

    /**
     * @param WardrobeItem[] $items живые вещи образа (уже отфильтрованные вызывающим кодом —
     *        тот же набор, что принят в WardrobeOutfit::items)
     *
     * @return string|null абсолютный путь готового файла; null — нет вещей/GD недоступен/сбой записи.
     *         Сбой НЕ должен ронять вызывающий HTTP-ответ агент-API — коллаж производный,
     *         образ в БД уже сохранён без него (вызывающий код оборачивает try/catch).
     */
    public function render(WardrobeOutfit $outfit, array $items): ?string
    {
        $id = $outfit->getId();
        if ($id === null || $items === [] || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $dst = $this->path($id);
        if (is_file($dst)) {
            return $dst;
        }
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $white);

        $occasion = $outfit->getOccasion();
        $title = ($occasion !== null ? WardrobeOutfit::DAILY_OCCASIONS[$occasion] ?? null : null) ?? $outfit->getTitle();
        $this->drawHeader($canvas, $title);

        foreach ($this->layout($items) as [$item, $rect]) {
            $this->drawTile($canvas, $item, $rect);
        }

        $ok = imagejpeg($canvas, $dst, 85);
        imagedestroy($canvas);

        return $ok ? $dst : null;
    }

    /** Заголовок повода — единственная подпись на холсте (см. докблок класса), без неё коллажи неотличимы друг от друга вне страницы. */
    private function drawHeader(\GdImage $canvas, string $text): void
    {
        $text = trim($text);
        if ($text === '' || !is_file($this->fontPath)) {
            return;
        }

        $ink = imagecolorallocate($canvas, 17, 24, 39); // tailwind gray-900
        $bbox = imagettfbbox(self::HEADER_FONT_SIZE, 0, $this->fontPath, $text);
        $tx = (int) ((self::WIDTH - ($bbox[2] - $bbox[0])) / 2);
        $baseline = (int) ((self::HEADER_HEIGHT + self::HEADER_FONT_SIZE) / 2);
        imagettftext($canvas, self::HEADER_FONT_SIZE, 0, max(self::MARGIN, $tx), $baseline, $ink, $this->fontPath, $text);

        // Тонкая линия-разделитель между заголовком и сеткой вещей.
        $rule = imagecolorallocate($canvas, 229, 231, 235); // tailwind gray-200
        imagefilledrectangle($canvas, self::MARGIN, self::HEADER_HEIGHT - 1, self::WIDTH - self::MARGIN, self::HEADER_HEIGHT, $rule);
    }

    /**
     * @param WardrobeItem[] $items
     *
     * @return list<array{0:WardrobeItem,1:array{0:int,1:int,2:int,3:int}}> вещь + прямоугольник ячейки [x,y,w,h]
     */
    private function layout(array $items): array
    {
        $x = self::MARGIN;
        $y = self::HEADER_HEIGHT + self::GAP;
        $w = self::WIDTH - 2 * self::MARGIN;
        $h = self::HEIGHT - $y - self::MARGIN;

        $compact = [];
        $tall = [];
        foreach ($items as $item) {
            if ($this->isCompact($item->getCategory())) {
                $compact[] = $item;
            } else {
                $tall[] = $item;
            }
        }

        // Все вещи компактные (редкий край — образ без единого «высокого» предмета):
        // строка снизу не нужна, раскладываем обычной сеткой на всю область.
        if ($tall === []) {
            return $this->grid($compact, $x, $y, $w, $h);
        }

        $stripH = $compact === [] ? 0 : (int) round($h * self::STRIP_HEIGHT_RATIO);
        $mainH = $stripH > 0 ? $h - $stripH - self::GAP : $h;

        $rects = $this->grid($tall, $x, $y, $w, $mainH);
        if ($compact !== []) {
            $rects = [...$rects, ...$this->row($compact, $x, $y + $mainH + self::GAP, $w, $stripH)];
        }

        return $rects;
    }

    /**
     * Сетка «высоких» вещей: НЕ БОЛЬШЕ 2 колонок в ряду, лишние вещи уходят в следующий ряд
     * (3 → 2+1, 4 → 2+2, 5 → 2+2+1). Ячейки заполняются фото целиком (cover, см. drawTile) —
     * форма ячейки только определяет, что именно кропается, пустых полей внутри неё не бывает.
     * Деградирует без падения и за пределами спеки 2-5 (просто больше рядов).
     *
     * @param WardrobeItem[] $items
     *
     * @return list<array{0:WardrobeItem,1:array{0:int,1:int,2:int,3:int}}>
     */
    private function grid(array $items, int $x, int $y, int $w, int $h): array
    {
        $n = count($items);
        if ($n === 0) {
            return [];
        }
        if ($n <= 2) {
            return $this->row($items, $x, $y, $w, $h);
        }

        return $this->rowsOf($items, 2, $x, $y, $w, $h);
    }

    /**
     * Разбить $items на ряды по $perRow штук сверху вниз, ряды равной высоты (последний
     * добирает остаток от округления, как row() делает по ширине) — общие вертикали и
     * горизонтали между рядами, никаких рваных краёв.
     *
     * @param WardrobeItem[] $items
     *
     * @return list<array{0:WardrobeItem,1:array{0:int,1:int,2:int,3:int}}>
     */
    private function rowsOf(array $items, int $perRow, int $x, int $y, int $w, int $h): array
    {
        $rowsOfItems = array_chunk($items, $perRow);
        $rowCount = count($rowsOfItems);
        $rowH = (int) round(($h - ($rowCount - 1) * self::GAP) / $rowCount);

        $rects = [];
        $cursorY = $y;
        foreach ($rowsOfItems as $i => $rowItems) {
            $isLast = $i === $rowCount - 1;
            $height = $isLast ? ($y + $h - $cursorY) : $rowH;
            $rects = [...$rects, ...$this->row($rowItems, $x, $cursorY, $w, $height)];
            $cursorY += $height + self::GAP;
        }

        return $rects;
    }

    /**
     * Один ряд из N равных по ширине ячеек (последняя добирает остаток от округления,
     * чтобы ряд заканчивался ровно на правом крае, а не с щелью). Одна вещь в ряду получает
     * ВСЮ ширину ряда — не узкую колонку по центру.
     *
     * @param WardrobeItem[] $items
     *
     * @return list<array{0:WardrobeItem,1:array{0:int,1:int,2:int,3:int}}>
     */
    private function row(array $items, int $x, int $y, int $w, int $h): array
    {
        $n = count($items);
        if ($n === 0) {
            return [];
        }
        $tileW = (int) round(($w - ($n - 1) * self::GAP) / $n);

        $rects = [];
        $cursor = $x;
        foreach ($items as $i => $item) {
            $isLast = $i === $n - 1;
            $width = $isLast ? ($x + $w - $cursor) : $tileW;
            $rects[] = [$item, [$cursor, $y, $width, $h]];
            $cursor += $width + self::GAP;
        }

        return $rects;
    }

    private function isCompact(?string $category): bool
    {
        if ($category === null || trim($category) === '') {
            return false; // по умолчанию — «высокая» вещь, большинство гардероба таково
        }
        $needle = mb_strtolower($category);
        foreach (self::COMPACT_KEYWORDS as $keyword) {
            if (mb_stripos($needle, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param array{0:int,1:int,2:int,3:int} $rect */
    private function drawTile(\GdImage $canvas, WardrobeItem $item, array $rect): void
    {
        [$x, $y, $w, $h] = $rect;

        $path = $this->resolvePhoto($item);
        $src = $path !== null ? $this->loadImage($path) : null;
        if ($src === null) {
            $this->drawPlaceholder($canvas, $item, $x, $y, $w, $h);

            return;
        }

        $anchorY = $this->isCompact($item->getCategory()) ? self::COMPACT_ANCHOR_Y : self::TALL_ANCHOR_Y;
        $this->copyCropped($canvas, $src, $x, $y, $w, $h, 0.5, $anchorY);
        imagedestroy($src);
    }

    /**
     * Плейсхолдер без фото (требование задачи: вещь без снимка не должна ломать коллаж) —
     * плашка цвета tailwind gray-100 (тот же тон, что у placeholder-тайла в _outfit_card.html.twig)
     * с названием и категорией/цветом вместо фото.
     */
    private function drawPlaceholder(\GdImage $canvas, WardrobeItem $item, int $x, int $y, int $w, int $h): void
    {
        $bg = imagecolorallocate($canvas, 243, 244, 246);
        imagefilledrectangle($canvas, $x, $y, $x + $w - 1, $y + $h - 1, $bg);

        if (!is_file($this->fontPath) || $w < 80 || $h < 80) {
            return; // без шрифта или в крошечной ячейке — просто плашка, коллаж не падает
        }

        $pad = 20;
        $maxWidth = max(10, $w - 2 * $pad);
        $blocks = [];
        foreach ($this->wrap((string) ($item->getName() ?: 'Без названия'), self::NAME_FONT_SIZE, $maxWidth, 2) as $line) {
            $blocks[] = [$line, self::NAME_FONT_SIZE];
        }
        // 2 строки, не 1: «категория · цвет» кириллицей в узкой колонке легко не влезает в одну
        // строку — обрезанное «Платье ·» без цвета выглядит как баг, а не как осознанное усечение.
        $meta = trim(implode(' · ', array_filter([$item->getCategory(), $item->getColorName()])));
        if ($meta !== '') {
            foreach ($this->wrap($meta, self::META_FONT_SIZE, $maxWidth, 2) as $line) {
                $blocks[] = [$line, self::META_FONT_SIZE];
            }
        }
        if ($blocks === []) {
            return;
        }

        $totalHeight = 0;
        foreach ($blocks as [, $size]) {
            $totalHeight += (int) round($size * self::LINE_HEIGHT_RATIO);
        }
        $ink = imagecolorallocate($canvas, 55, 65, 81);
        $muted = imagecolorallocate($canvas, 107, 114, 128);

        // $top — верх текущей строки; baseline (для imagettftext) — на $size ниже него.
        $top = $y + intdiv($h - $totalHeight, 2);
        foreach ($blocks as [$text, $size]) {
            $color = $size === self::NAME_FONT_SIZE ? $ink : $muted;
            $bbox = imagettfbbox($size, 0, $this->fontPath, $text);
            $tx = $x + intdiv($w - ($bbox[2] - $bbox[0]), 2);
            imagettftext($canvas, $size, 0, $tx, $top + $size, $color, $this->fontPath, $text);
            $top += (int) round($size * self::LINE_HEIGHT_RATIO);
        }
    }

    /** @return string[] не больше $maxLines строк, обрезанных по фактической ширине пикселей */
    private function wrap(string $text, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $bbox = imagettfbbox($size, 0, $this->fontPath, $candidate);
            if ($current !== '' && ($bbox[2] - $bbox[0]) > $maxWidth) {
                $lines[] = $current;
                if (count($lines) >= $maxLines) {
                    return $lines;
                }
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines, 0, $maxLines);
    }

    /**
     * Фото вещи: обложка галереи → основное поле → legacy public_html/images/wardrobe.
     * Тот же порядок и тот же legacy-фолбэк, что в WardrobeMediaController::mediaResponse() и
     * WardrobeDailyController::preparePhoto()/resolveMediaPath() — часть импортированных фото
     * физически лежит в public_html/images/wardrobe, Vich resolvePath() их там не находит.
     */
    private function resolvePhoto(WardrobeItem $item): ?string
    {
        $prepared = PreparedWardrobePhoto::path($this->projectDir, $item);
        if ($prepared !== null && is_file($prepared)) {
            return $prepared;
        }

        $cover = $item->getCoverPhoto();
        if ($cover !== null) {
            $path = $this->resolveMediaPath($this->storage->resolvePath($cover, 'file'), $cover->getFilePath());
            if ($path !== null) {
                return $path;
            }
        }

        return $this->resolveMediaPath($this->storage->resolvePath($item, 'photoFile'), $item->getPhoto());
    }

    private function resolveMediaPath(?string $vichPath, ?string $legacyName): ?string
    {
        if ($vichPath !== null && is_file($vichPath)) {
            return $vichPath;
        }
        if ($legacyName === null || basename($legacyName) !== $legacyName) {
            return null;
        }
        $root = realpath($this->projectDir . '/public_html/images/wardrobe');
        if ($root === false) {
            return null;
        }
        foreach ([$legacyName, mb_substr($legacyName, 0, 2) . '/' . mb_substr($legacyName, 2, 2) . '/' . $legacyName] as $relativePath) {
            $legacyPath = realpath($root . '/' . $relativePath);
            if ($legacyPath !== false && str_starts_with($legacyPath, $root . DIRECTORY_SEPARATOR)) {
                return $legacyPath;
            }
        }

        return null;
    }

    /**
     * Декодирует фото в GD с потолком по площади ДО декодирования (getimagesize — дёшево,
     * без загрузки байтов в GD): один аномально большой кадр иначе распухает в truecolor
     * (4 байта/px) и рискует выбить memory_limit веб-запроса агент-API. Свыше потолка —
     * как отсутствующее фото (плейсхолдер), не падение.
     */
    private function loadImage(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        $size = @getimagesize($path);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1 || ($size[0] * $size[1]) > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $bytes = @file_get_contents($path);
        $image = $bytes !== false ? @imagecreatefromstring($bytes) : false;

        return $image instanceof \GdImage ? $image : null;
    }

    /**
     * Cover-кроп источника в целевой прямоугольник целиком (как object-fit: cover — без полей
     * внутри ячейки, см. докблок класса), с якорем $anchorY по вертикали (0=верх, 1=низ,
     * 0.5=центр — см. TALL_ANCHOR_Y/COMPACT_ANCHOR_Y). Приём — как у
     * LookShareOgCardRenderer::copyCropped(), плюс управляемый якорь вместо жёсткого центра.
     */
    private function copyCropped(\GdImage $dst, \GdImage $src, int $dx, int $dy, int $dw, int $dh, float $anchorX = 0.5, float $anchorY = 0.5): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return;
        }
        $scale = max($dw / $sw, $dh / $sh);
        $cw = (int) max(1, round($dw / $scale));
        $ch = (int) max(1, round($dh / $scale));
        $cx = (int) max(0, min($sw - $cw, (int) round(($sw - $cw) * $anchorX)));
        $cy = (int) max(0, min($sh - $ch, (int) round(($sh - $ch) * $anchorY)));

        imagecopyresampled($dst, $src, $dx, $dy, $cx, $cy, $dw, $dh, $cw, $ch);
    }
}
