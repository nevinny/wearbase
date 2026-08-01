<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialPost;
use Symfony\Component\Process\Process;

/**
 * Reels-слайдшоу из уже нормализованных слайдов карусели (GallerySlideRenderer).
 * Reels — единственная поверхность Instagram с существенной раздачей НЕ подписчикам,
 * поэтому тот же контент отдаём и туда, не порождая новых источников материала.
 *
 * Холст 9:16 (1080×1920): слайд 4:5 вписывается с полями. Звук — трек из локальной библиотеки
 * `config/social/audio` (Mixkit Free License, коммерческое использование без атрибуции):
 * трендовое аудио через API легально не приклеить (docs/marketing_instagram.md §5), а совсем
 * без аудиодорожки контейнер Reels принимается нестабильно. Трек выбирается детерминированно
 * по id поста (id % количество треков) — соседние посты звучат по-разному, повторный рендер
 * того же поста даёт тот же трек. Если библиотека пуста — мягкая деградация на тишину
 * (`anullsrc`), клип всё равно должен собраться.
 *
 * ⚠️ cron PATH пуст — ffmpeg ищем по абсолютным путям, как rsync в PublicMediaHost.
 */
class ReelsSlideshowRenderer
{
    private const FFMPEG_CANDIDATES = [
        '/opt/homebrew/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/usr/bin/ffmpeg',
    ];

    private const WIDTH  = 1080;
    private const HEIGHT = 1920;
    private const FPS    = 30;

    /**
     * Секунд на слайд. Было 2.5 (10 слайдов = 27с статики) — для слайдшоу без движения долго;
     * 1.5с даёт ~15с, ближе к рабочему диапазону 15–30с при живом темпе.
     */
    private const SECONDS_PER_SLIDE = 1.5;

    /**
     * Последний кадр (развязка) — три строки текста (имя бренда, город/категории, просьба
     * сохранить), за 1.5с прочитать не успеть, поэтому ему выделяется отдельная, более долгая
     * длительность. Итоговая длина клипа: (N−1)×SECONDS_PER_SLIDE + LAST_SLIDE_SECONDS.
     */
    private const LAST_SLIDE_SECONDS = 3.0;

    /**
     * Глубина микро-зума за слайд. 6% почти незаметны кадр-к-кадру, но убирают ощущение
     * мёртвой презентации, из-за которого клиповый зритель уходит на первых секундах.
     */
    private const ZOOM_RANGE = 0.06;

    /**
     * Во сколько раз апскейлить слайд ПЕРЕД zoompan. Прямая подача 1080×1920 в zoompan даёт
     * приращение зума 0.06/45≈0.0013 за кадр — на исходном разрешении это доли пикселя, zoompan
     * округляет x/y до целых, и получившиеся ступеньки видны как дрожь картинки. Апскейл в 3
     * раза (3240×5760) переводит то же приращение в межпиксельный шаг исходника ×3 меньше, а
     * downscale обратно в 1080×1920 (через собственный `s=` zoompan, без отдельного шага)
     * сглаживает округление интерполяцией — дрожь визуально пропадает.
     */
    private const ZOOM_UPSCALE = 3;

    /** Минимальная длительность Reels у Instagram — 3 секунды. */
    private const MIN_DURATION_SEC = 3.0;

    /** Фейд-ин/фейд-аут фоновой музыки — чтобы трек не начинался и не обрывался резко. */
    private const AUDIO_FADE_IN_SEC = 0.6;
    private const AUDIO_FADE_OUT_SEC = 0.8;

    public function __construct(
        private readonly string $projectDir,
        private readonly GallerySlideRenderer $slideRenderer,
    ) {
    }

    /**
     * Собрать mp4 из слайдов. Возвращает публичный путь или null, если собрать нечем.
     *
     * @param list<string> $slidePublicPaths слайды по порядку (/images/social/gallery/...)
     */
    public function render(SocialPost $post, array $slidePublicPaths): ?string
    {
        if ($slidePublicPaths === []) {
            return null;
        }
        $estimated = (count($slidePublicPaths) - 1) * self::SECONDS_PER_SLIDE + self::LAST_SLIDE_SECONDS;
        if ($estimated < self::MIN_DURATION_SEC) {
            return null;
        }

        $dir = $this->projectDir . '/public_html' . $this->publicDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $name = sprintf('p%d.mp4', (int) $post->getId());
        $outAbs = $dir . '/' . $name;
        if (is_file($outAbs)) {
            return $this->publicDir() . '/' . $name;
        }

        $plan = $this->planSlides($slidePublicPaths, (int) $post->getId());
        if ($plan === null) {
            return null;
        }

        $this->runFfmpeg($plan['slides'], $plan['duration'], $outAbs, (int) $post->getId());

        return is_file($outAbs) ? $this->publicDir() . '/' . $name : null;
    }

    public function publicDir(): string
    {
        return '/images/social/reels';
    }

    /**
     * План слайдов: абсолютный путь + длительность каждого. Развязка (последний
     * СУЩЕСТВУЮЩИЙ слайд, а не последний элемент входного списка — часть путей может не найтись
     * на диске) получает LAST_SLIDE_SECONDS вместо SECONDS_PER_SLIDE.
     *
     * Фото и статичный UI (счётчик/прогресс/текст) — РАЗНЫЕ файлы (см. runFfmpeg): `file` —
     * чистый фото/лого-слой без UI (GallerySlideRenderer::reelsCleanPhotoPath), его зумит
     * ffmpeg; `overlay` — прозрачный PNG того же кадра (reelsOverlayPath), накладывается
     * неподвижно. Позиция для поиска обоих — исходный (до фильтрации отсутствующих файлов)
     * индекс в $slidePublicPaths: именно под ним GallerySlideRenderer::render() их сохранил.
     *
     * @param list<string> $slidePublicPaths
     *
     * @return array{slides: list<array{file: string, overlay: ?string, seconds: float}>, duration: float}|null
     *         duration нужна для фейд-аута музыки
     */
    private function planSlides(array $slidePublicPaths, int $postId): ?array
    {
        $files = [];
        foreach ($slidePublicPaths as $i => $public) {
            $abs = $this->projectDir . '/public_html' . $public;
            if (is_file($abs)) {
                $files[] = ['abs' => $abs, 'position' => $i + 1];
            }
        }

        if ($files === []) {
            return null;
        }

        $lastIndex = count($files) - 1;
        $slides = [];
        $duration = 0.0;
        foreach ($files as $i => $entry) {
            $seconds = $i === $lastIndex ? self::LAST_SLIDE_SECONDS : self::SECONDS_PER_SLIDE;
            [$file, $overlay] = $this->reelsLayers($entry['abs'], $postId, $entry['position']);
            $slides[] = ['file' => $file, 'overlay' => $overlay, 'seconds' => $seconds];
            $duration += $seconds;
        }

        return ['slides' => $slides, 'duration' => $duration];
    }

    /**
     * Чистый фото-слой + статичный оверлей для позиции $position, если оба отрендерены
     * (GallerySlideRenderer это делает попутно с каруселью — см. renderReelsCleanLayer/
     * buildReelsOverlay). Деградация: если хотя бы одного нет на диске (напр. повторный
     * рендер поста, у которого исходное фото бренда уже удалено), используем $bakedAbs —
     * тот же слайд, что и в карусели, со вжаренным UI — как раньше, без разделения слоёв.
     *
     * @return array{0: string, 1: ?string} [файл фото, файл оверлея или null]
     */
    private function reelsLayers(string $bakedAbs, int $postId, int $position): array
    {
        $cleanAbs = $this->projectDir . '/public_html' . $this->slideRenderer->reelsCleanPhotoPath($postId, $position);
        $overlayAbs = $this->projectDir . '/public_html' . $this->slideRenderer->reelsOverlayPath($postId, $position);

        if (is_file($cleanAbs) && is_file($overlayAbs)) {
            return [$cleanAbs, $overlayAbs];
        }

        return [$bakedAbs, null];
    }

    /**
     * Раньше слайды склеивались concat-демуксером из общего списка файлов. На синтетических
     * (однотонных) JPEG это работало, но на реальных фото брендов клип ВСЕГДА обрывался на
     * одном и том же 137-м кадре независимо от числа слайдов: GD пишет обычные фото с
     * imagejpeg(..., 88) как yuvj420p, а слайды с наложенным текстом (h1/h2/биты/развязка/лого,
     * imagejpeg(..., 90) — качество ≥90 переключает libjpeg на 4:4:4) — как yuvj444p. Когда
     * concat-демуксер подряд скармливает decoder'у кадры с разным pix_fmt, ffmpeg пересобирает
     * фильтр-граф на лету, и стейтфул zoompan (несбрасываемый счётчик кадров) обрубает поток.
     *
     * Фикс — каждый слайд отдельным входом (`-loop 1 -framerate FPS -t <dur> -i slide.jpg`):
     * так input уже отдаёт ровно round(dur*FPS) идентичных декодированных кадров нужного
     * pix_fmt/размера, а zoompan на КАЖДЫЙ слайд — свой отдельный узел графа с собственным
     * счётчиком on (внутри своего d=1, 1 входной кадр = 1 выходной), поэтому масштаб сам
     * стартует с 1.0 на границе слайда — без демуксера ронять нечего, сброс зума бесплатный.
     * Проверено на реальных p136-* (yuvj420p+yuvj444p вперемешку): 10 слайдов → ровно 495
     * кадров/16.5с, что и требует формула (N−1)×1.5+3.0.
     *
     * Фото и UI — отдельные входы (см. planSlides/reelsLayers): фото идёт через upscale→zoompan
     * (ZOOM_UPSCALE — иначе субпиксельные шаги зума округляются в целые и картинка дрожит), UI
     * — статичным слоем, который просто держится $slide['seconds'] и накладывается сверху
     * (`overlay`) БЕЗ зума, поэтому счётчик/прогресс/текст на экране не увеличиваются и не
     * дрожат. Если оверлея нет (деградация в reelsLayers — старый слайд с уже вжаренным UI),
     * слайд получает только фото-ветвь, как до разделения слоёв.
     *
     * @param list<array{file: string, overlay: ?string, seconds: float}> $slides
     */
    private function runFfmpeg(array $slides, float $duration, string $outAbs, int $postId): void
    {
        $inputs = [];
        $filterParts = [];
        $labels = [];
        $inputIndex = 0;
        foreach ($slides as $i => $slide) {
            $frames = (int) round($slide['seconds'] * self::FPS);
            $photoIndex = $inputIndex++;
            $inputs[] = '-loop';
            $inputs[] = '1';
            $inputs[] = '-framerate';
            $inputs[] = (string) self::FPS;
            $inputs[] = '-t';
            $inputs[] = (string) $slide['seconds'];
            $inputs[] = '-i';
            $inputs[] = $slide['file'];

            // in_range=pc→out_range=tv: JPEG-слайды полнодиапазонные, без явной конверсии ffmpeg
            // оставляет pix_fmt full range, а спека Meta ждёт обычный 4:2:0 (limited). scale в
            // ZOOM_UPSCALE раз ПЕРЕД zoompan — фикс дрожи (см. константу); сам zoompan уже
            // ужимает обратно до WIDTHxHEIGHT через свой s=, отдельный downscale не нужен.
            $baseLabel = 'base' . $i;
            $filterParts[] = sprintf(
                '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease:in_range=pc:out_range=tv,'
                . 'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=white,setsar=1,'
                . 'scale=%d:%d,'
                . 'zoompan=z=\'1+%.4f*on/%d\':d=1:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=%dx%d:fps=%d[%s]',
                $photoIndex,
                self::WIDTH,
                self::HEIGHT,
                self::WIDTH,
                self::HEIGHT,
                self::WIDTH * self::ZOOM_UPSCALE,
                self::HEIGHT * self::ZOOM_UPSCALE,
                self::ZOOM_RANGE,
                $frames,
                self::WIDTH,
                self::HEIGHT,
                self::FPS,
                $baseLabel,
            );

            $label = 'v' . $i;
            $labels[] = '[' . $label . ']';
            if ($slide['overlay'] !== null) {
                $overlayIndex = $inputIndex++;
                $inputs[] = '-loop';
                $inputs[] = '1';
                $inputs[] = '-framerate';
                $inputs[] = (string) self::FPS;
                $inputs[] = '-t';
                $inputs[] = (string) $slide['seconds'];
                $inputs[] = '-i';
                $inputs[] = $slide['overlay'];

                $ovlLabel = 'ovl' . $i;
                $filterParts[] = sprintf(
                    '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,'
                    . 'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=0x00000000,format=rgba[%s]',
                    $overlayIndex,
                    self::WIDTH,
                    self::HEIGHT,
                    self::WIDTH,
                    self::HEIGHT,
                    $ovlLabel,
                );
                // format=yuv420 внутри overlay — то же требование лимитед-диапазона 4:2:0, что
                // раньше давал отдельный `format` фильтр; после наложения оно уже не нужно.
                $filterParts[] = sprintf('[%s][%s]overlay=format=yuv420,format=yuv420p[%s]', $baseLabel, $ovlLabel, $label);
            } else {
                $filterParts[] = sprintf('[%s]format=yuv420p[%s]', $baseLabel, $label);
            }
        }
        $audioIndex = $inputIndex;
        $filterParts[] = implode('', $labels) . sprintf('concat=n=%d:v=1:a=0[outv]', count($slides));
        $filterComplex = implode(';', $filterParts);

        // Реальный трек зациклен на всю длину клипа (-stream_loop -1), -shortest потом обрежет
        // его до длины видео. Пустая библиотека/её отсутствие → тишина, как раньше.
        $track = $this->selectTrack($postId);
        $audioInput = $track !== null
            ? ['-stream_loop', '-1', '-i', $track]
            : ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100'];
        $audioFilter = $track !== null ? ['-af', $this->audioFadeFilter($duration)] : [];

        $process = new Process([
            $this->resolveFfmpeg(),
            '-y', '-hide_banner', '-loglevel', 'error',
            ...$inputs,
            ...$audioInput,
            '-filter_complex', $filterComplex,
            '-map', '[outv]', '-map', $audioIndex . ':a',
            ...$audioFilter,
            '-r', (string) self::FPS,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '128k',
            '-shortest',
            // Спека Meta для Reels: «no edit lists, moov atom at front». faststart двигает moov
            // вперёд — проверено. negative_cts_offsets/avoid_negative_ts должны снижать нужду в
            // edit list, но ДВА elst-бокса (видео+аудио) ffmpeg всё равно пишет из-за праймера
            // AAC — убрать их без внешнего ремуксера (GPAC) не получилось. Instagram такой файл
            // принимает и классифицирует как REELS, так что отклонение от буквы спеки терпим.
            '-movflags', '+faststart+negative_cts_offsets',
            '-avoid_negative_ts', 'make_zero',
            $outAbs,
        ], timeout: 300);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg не собрал Reels: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /** Фейд-ин с начала + фейд-аут к моменту, когда -shortest обрежет трек по длине видео. */
    private function audioFadeFilter(float $duration): string
    {
        $fadeOutStart = max(0.0, $duration - self::AUDIO_FADE_OUT_SEC);

        return sprintf(
            'afade=t=in:st=0:d=%.2f,afade=t=out:st=%.3f:d=%.2f',
            self::AUDIO_FADE_IN_SEC,
            $fadeOutStart,
            self::AUDIO_FADE_OUT_SEC,
        );
    }

    /**
     * Трек для конкретного поста — детерминированно по id (id % количество треков), чтобы
     * соседние посты звучали по-разному, а повторный рендер того же поста давал тот же трек.
     * Пустая библиотека → null, вызывающий код деградирует на anullsrc.
     */
    private function selectTrack(int $postId): ?string
    {
        $tracks = $this->listTracks();
        if ($tracks === []) {
            return null;
        }

        return $tracks[abs($postId) % count($tracks)];
    }

    /** @return list<string> абсолютные пути m4a/mp3 из config/social/audio, сортировка по имени — детерминизм */
    private function listTracks(): array
    {
        $dir = $this->projectDir . '/config/social/audio';
        if (!is_dir($dir)) {
            return [];
        }

        $tracks = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/\.(m4a|mp3)$/i', $entry) === 1) {
                $tracks[] = $dir . '/' . $entry;
            }
        }
        sort($tracks);

        return $tracks;
    }

    private function resolveFfmpeg(): string
    {
        foreach (self::FFMPEG_CANDIDATES as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('ffmpeg не найден ни по одному из известных путей.');
    }
}
