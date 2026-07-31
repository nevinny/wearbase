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
 *
 * Текст на слайдах — сценарий v3 (SlideScript): hookA на первом кадре, hookA+hookB на втором
 * (плашка верхне-выровнена — hookA не сдвигается), 0..3 бита-факта на кадрах 4,6,8…, развязка
 * (имя, «город · категории», просьба сохранить) на последнем. Слова пишет SlideScriptComposer,
 * здесь только геометрия: где стоит плашка и на каком кадре что появляется.
 */
class GallerySlideRenderer
{
    public const WIDTH  = 1080;
    public const HEIGHT = 1350;

    /** Логотип занимает эту долю ширины холста. */
    private const LOGO_WIDTH_RATIO = 0.62;

    /** Суффикс имени слайда с логотипом — по нему отличаем его от фотографий. */
    private const LOGO_SUFFIX = '-logo.jpg';

    /**
     * Суффиксы слайдов с надписями сценария v3 (SlideScript): hookA, hookA+hookB, биты b1..b3,
     * развязка. Надпись всегда пишется в ОТДЕЛЬНЫЙ файл, а исходный слайд остаётся чистым —
     * из него берётся обложка Reels (coverSlide) и он же переиспользуется при повторном рендере.
     */
    private const SUFFIX_HOOK_A    = 'h1';
    private const SUFFIX_HOOK_B    = 'h2';
    private const SUFFIX_BIT_PREFIX = 'b';
    private const SUFFIX_FINALE    = 'fin';

    private const TITLE_FONT_SIZE  = 52;
    private const FOOTER_FONT_SIZE = 28;
    /** hookA/hookB и биты-факты — крупный кегль, тот же вес, что заголовок развязки. */
    private const HOOK_FONT_SIZE   = 54;
    /** Мета-строка и просьба на развязке — мельче имени бренда, это иерархия. */
    private const FINALE_TEXT_FONT_SIZE = 40;
    private const COUNTER_FONT_SIZE = 34;

    /** Межстрочный интервал = кегль × это: 54 → 70 px. */
    private const LINE_HEIGHT_RATIO = 1.3;

    /** Зазор между группами строк разного кегля внутри плашки развязки (имя ↔ мета/просьба). */
    private const BAND_GROUP_GAP = 16;

    /** Поле слайда с каждой стороны — тот же пиксельный бюджет, что у SlideTextBudget. */
    private const MARGIN_PX = 64;

    /**
     * Все надписи клипа стоят на одной высоте — 0.62 холста. Единая позиция важнее «красивого
     * центра» у каждой: глаз знает, куда смотреть, и не тратит на поиск текста часть тех самых
     * 1.5 секунды. 0.62 (нижняя треть) выбрана вместо центра, чтобы не закрывать вещь на фото,
     * и при этом с запасом выше bottomLimitY() — зоны, перекрытой интерфейсом Reels.
     */
    private const BAND_CENTER_RATIO = 0.62;

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
     * Отрендерить слайды поста: нормализованные фото + слайд с логотипом в начале или конце,
     * поверх — надписи сценария. Уже отрендеренные файлы переиспользуются (идемпотентно по id
     * поста), поэтому смена формулировок требует чистки каталога слайдов.
     *
     * @param list<string> $sources публичные пути исходных фото (/images/brands/...)
     *
     * @return list<string> публичные пути слайдов по порядку; [] если рендер невозможен
     */
    public function render(SocialPost $post, array $sources, bool $logoFirst, ?SlideScript $script = null): array
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

        $brand = $post->getBrand();
        if ($brand !== null) {
            $logoName = 'p' . $postId . self::LOGO_SUFFIX;
            // Слайд логотипа всегда оказывается либо первым (там хук), либо последним (там CTA),
            // то есть при наличии сценария на нём заведомо будет надпись. Плашка поверх обычной
            // раскладки накрыла бы сам логотип, поэтому такой слайд рисуется компактным:
            // логотип выше и меньше, нижняя треть свободна под текст.
            if ($this->logoSlide($brand, $dir . '/' . $logoName, $logoFirst ? 1 : $total, $total, $script !== null)) {
                $logoPath = $this->publicDir() . '/' . $logoName;
                if ($logoFirst) {
                    array_unshift($slides, $logoPath);
                } else {
                    $slides[] = $logoPath;
                }
            }
        }

        // Надписи накладываются на УЖЕ СОБРАННУЮ последовательность, а не по ходу её сборки:
        // позиции считаются от финального порядка слайдов, поэтому текст встаёт на первый /
        // средний / последний кадр в обеих ветках A/B одинаково — независимо от того, куда попал
        // логотип и удалось ли его вообще отрисовать (раньше при отсутствующем логотипе ветка
        // logo_first оставалась совсем без хука).
        return $script !== null ? $this->applyScript($postId, $dir, $slides, $script) : $slides;
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

    /**
     * Слайд-обложка: логотип бренда по центру + название и подпись движения.
     *
     * $compact — освободить нижнюю треть под надпись сценария (хук или CTA): логотип уезжает
     * выше и уменьшается, название поднимается над плашкой.
     */
    private function logoSlide(Brand $brand, string $dstAbs, int $index, int $total, bool $compact = false): bool
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

        $ratio = $compact ? 0.44 : self::LOGO_WIDTH_RATIO;
        $boxWidth = (int) (self::WIDTH * $ratio);
        $boxHeight = $boxWidth;
        $boxX = (int) ((self::WIDTH - $boxWidth) / 2);
        $boxY = (int) (self::HEIGHT * ($compact ? 0.06 : 0.22));
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
            // 0.47 в компактной раскладке: название уже ниже логотипа, но ещё выше плашки,
            // верхний край которой при трёхстрочном CTA приходится на ~0.52 холста.
            $titleY = (int) (self::HEIGHT * ($compact ? 0.47 : 0.78));
            // В компактной раскладке (логотип идёт ПЕРВЫМ и на нём же лежит хук) название
            // бренда не печатаем: по Шварцу имя незнакомой марки вести не должно, а крупный
            // заголовок перетягивал внимание с хука в решающие полторы секунды.
            if (!$compact) {
                $this->centeredText($canvas, (string) $brand->getTitle(), self::TITLE_FONT_SIZE, $titleY, $ink);
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
     * Разложить надписи сценария v3 по собранной последовательности слайдов: hookA на первом
     * кадре, hookA+hookB на втором, биты на 4,6,8…, развязка на последнем. Индексы считаются от
     * финального порядка, поэтому в обеих ветках A/B надписи стоят в одних и тех же МОМЕНТАХ
     * клипа — различие между ветками остаётся ровно одно: что за фон под текстом.
     *
     * На очень короткой последовательности (2-3 кадра) позиция hookB/бита может совпасть с
     * последним кадром — тогда развязка ПОБЕЖДАЕТ (назначается позже остальных в $overlays и
     * перезаписывает более раннее назначение той же позиции): это единственный кадр с именем
     * бренда и просьбой сохранить, отдавать его под хук нельзя.
     *
     * @param list<string> $slides
     *
     * @return list<string>
     */
    private function applyScript(int $postId, string $dir, array $slides, SlideScript $script): array
    {
        $total = count($slides);
        if ($total < 1) {
            return $slides;
        }

        /** @var array<int, array{0:string,1:\Closure}> $overlays индекс кадра => [суффикс, рисовалка] */
        $overlays = [];

        $overlays[0] = [self::SUFFIX_HOOK_A, fn (\GdImage $c) => $this->drawBigLineBand($c, $script->hookA, null)];

        if ($total >= 2) {
            $overlays[1] = [self::SUFFIX_HOOK_B, fn (\GdImage $c) => $this->drawBigLineBand($c, $script->hookA, $script->hookB)];
        }

        $bitCount = min(count($script->bits), SlideScript::maxBits($total));
        foreach (SlideScript::bitFrameIndices($bitCount) as $i => $frame) {
            if ($frame > $total) {
                break; // защита от рассинхрона с реальным $total (напр. логотип не отрисовался)
            }
            $text = $script->bits[$i];
            $overlays[$frame - 1] = [self::SUFFIX_BIT_PREFIX . ($i + 1), fn (\GdImage $c) => $this->drawBigLineBand($c, $text, null)];
        }

        $overlays[$total - 1] = [self::SUFFIX_FINALE, fn (\GdImage $c) => $this->drawFinaleBand($c, $script)];

        foreach ($overlays as $idx => [$suffix, $draw]) {
            $slides[$idx] = $this->overlay($postId, $dir, $slides[$idx], $suffix, $draw);
        }

        return $slides;
    }

    /**
     * Копия слайда с плашкой-надписью в отдельном файле p{id}-{suffix}.jpg. Исходный слайд не
     * трогаем: из p{id}-01.jpg берётся обложка Reels, и она должна остаться без текста.
     *
     * @param \Closure(\GdImage):void $draw рисует надпись поверх канваса
     *
     * @return string публичный путь слайда с надписью или исходный, если наложить не удалось
     */
    private function overlay(int $postId, string $dir, string $slidePublic, string $suffix, \Closure $draw): string
    {
        $dstName = sprintf('p%d-%s.jpg', $postId, $suffix);
        $dstAbs = $dir . '/' . $dstName;
        $public = $this->publicDir() . '/' . $dstName;

        if (is_file($dstAbs)) {
            return $public;
        }

        $srcAbs = $dir . '/' . basename($slidePublic);
        if (!is_file($srcAbs) || !is_file($this->fontPath)) {
            return $slidePublic;
        }

        $bytes = @file_get_contents($srcAbs);
        $canvas = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($canvas === false) {
            return $slidePublic;
        }

        $draw($canvas);
        $ok = imagejpeg($canvas, $dstAbs, 90);
        imagedestroy($canvas);

        return $ok ? $public : $slidePublic;
    }

    /**
     * Одна крупная строка (hookA-only, бит) или две (hookA+hookB). Высота плашки ВСЕГДА
     * рассчитана под две строки — hookA не должен сдвигаться, когда на следующем кадре
     * появляется hookB: позиция первой строки одна и та же независимо от того, рисуем мы одну
     * строку или две (композер уже гарантировал, что обе укладываются в пиксельный бюджет).
     */
    private function drawBigLineBand(\GdImage $canvas, string $primary, ?string $secondary): void
    {
        $primary = trim($primary);
        if ($primary === '') {
            return;
        }
        $secondary = $secondary !== null ? trim($secondary) : null;

        $lineHeight = (int) round(self::HOOK_FONT_SIZE * self::LINE_HEIGHT_RATIO);
        $blockHeight = self::HOOK_FONT_SIZE + $lineHeight;
        $top = (int) (self::HEIGHT * self::BAND_CENTER_RATIO) - intdiv($blockHeight, 2);

        $scrim = imagecolorallocatealpha($canvas, 17, 24, 39, 40);
        imagefilledrectangle($canvas, 0, $top - 40, self::WIDTH - 1, $top + $blockHeight + 24, $scrim);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $this->centeredText($canvas, $primary, self::HOOK_FONT_SIZE, $top + self::HOOK_FONT_SIZE, $white);
        if ($secondary !== null && $secondary !== '') {
            $this->centeredText($canvas, $secondary, self::HOOK_FONT_SIZE, $top + self::HOOK_FONT_SIZE + $lineHeight, $white);
        }
    }

    /**
     * Развязка: ИНВЕРСНАЯ плашка (светлый фон, тёмный текст) — противоположность тёмному скриму
     * хука/битов, чтобы кадр читался как «итог», а не продолжение вопроса. Имя бренда (до двух
     * строк, не провалидировано композером — реальный текст, поэтому оборачиваем по пикселям
     * здесь же) + мета-строка «город · категории» + просьба сохранить, мельче.
     */
    private function drawFinaleBand(\GdImage $canvas, SlideScript $script): void
    {
        $lines = [];
        foreach ($this->wrapTitle($script->finaleTitle, 2) as $titleLine) {
            $lines[] = [$titleLine, self::HOOK_FONT_SIZE];
        }
        $meta = trim($script->finaleMeta);
        if ($meta !== '') {
            $lines[] = [$meta, self::FINALE_TEXT_FONT_SIZE];
        }
        $ask = trim($script->finaleAsk);
        if ($ask !== '') {
            $lines[] = [$ask, self::FINALE_TEXT_FONT_SIZE];
        }
        if ($lines === []) {
            return;
        }

        $baselines = [];
        $cursor = 0;
        $prevSize = null;
        foreach ($lines as [, $size]) {
            if ($prevSize !== null && $prevSize !== $size) {
                $cursor += self::BAND_GROUP_GAP;
            }
            $baselines[] = $cursor + $size;
            $cursor += (int) round($size * self::LINE_HEIGHT_RATIO);
            $prevSize = $size;
        }

        $top = (int) (self::HEIGHT * self::BAND_CENTER_RATIO) - intdiv($cursor, 2);

        $scrim = imagecolorallocatealpha($canvas, 245, 245, 245, 40);
        imagefilledrectangle($canvas, 0, $top - 40, self::WIDTH - 1, $top + $cursor + 24, $scrim);

        $ink = imagecolorallocate($canvas, 17, 24, 39);
        foreach ($lines as $i => [$text, $size]) {
            $this->centeredText($canvas, $text, $size, $top + $baselines[$i], $ink);
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

    /**
     * Имя бренда на развязке — единственный текст, который не провалидирован композером
     * заранее (это реальное название, а не сконструированная строка), поэтому оборачиваем по
     * ФАКТИЧЕСКОЙ ширине пикселей (тот же бюджет 1080−2×64), а не по числу знаков: кириллица
     * разной ширины на глаз символьным лимитом не оценить.
     *
     * @return list<string> не больше $maxLines строк
     */
    private function wrapTitle(string $text, int $maxLines): array
    {
        $maxWidth = self::WIDTH - 2 * self::MARGIN_PX;
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $bbox = imagettfbbox(self::HOOK_FONT_SIZE, 0, $this->fontPath, $candidate);
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
