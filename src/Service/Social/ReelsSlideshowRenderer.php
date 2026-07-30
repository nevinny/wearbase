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
 * Холст 9:16 (1080×1920): слайд 4:5 вписывается с полями. Звук — тишина (AAC): трендовое
 * аудио через API легально не приклеить (docs/marketing_instagram.md §5), а совсем без
 * аудиодорожки контейнер Reels принимается нестабильно.
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

    /** Секунд на слайд: 9 слайдов → 22.5с, попадает в комфортный для Reels диапазон. */
    private const SECONDS_PER_SLIDE = 2.5;

    /** Минимальная длительность Reels у Instagram — 3 секунды. */
    private const MIN_DURATION_SEC = 3.0;

    public function __construct(
        private readonly string $projectDir,
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
        if (count($slidePublicPaths) * self::SECONDS_PER_SLIDE < self::MIN_DURATION_SEC) {
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

        $listFile = $this->writeConcatList($slidePublicPaths, $dir, (int) $post->getId());
        if ($listFile === null) {
            return null;
        }

        try {
            $this->runFfmpeg($listFile, $outAbs);
        } finally {
            @unlink($listFile);
        }

        return is_file($outAbs) ? $this->publicDir() . '/' . $name : null;
    }

    public function publicDir(): string
    {
        return '/images/social/reels';
    }

    /**
     * Список для concat-демуксера. Последний файл дублируется без duration — иначе
     * ffmpeg отдаёт последнему слайду один кадр вместо полной длительности.
     *
     * @param list<string> $slidePublicPaths
     */
    private function writeConcatList(array $slidePublicPaths, string $dir, int $postId): ?string
    {
        $lines = [];
        $lastAbs = null;
        foreach ($slidePublicPaths as $public) {
            $abs = $this->projectDir . '/public_html' . $public;
            if (!is_file($abs)) {
                continue;
            }
            $lines[] = "file '" . $abs . "'";
            $lines[] = 'duration ' . self::SECONDS_PER_SLIDE;
            $lastAbs = $abs;
        }

        if ($lastAbs === null) {
            return null;
        }
        $lines[] = "file '" . $lastAbs . "'";

        $listFile = $dir . '/p' . $postId . '.concat.txt';

        return @file_put_contents($listFile, implode("\n", $lines) . "\n") !== false ? $listFile : null;
    }

    private function runFfmpeg(string $listFile, string $outAbs): void
    {
        $filter = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=white,format=yuv420p',
            self::WIDTH,
            self::HEIGHT,
            self::WIDTH,
            self::HEIGHT,
        );

        $process = new Process([
            $this->resolveFfmpeg(),
            '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-vf', $filter,
            '-r', (string) self::FPS,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '96k',
            '-shortest', '-movflags', '+faststart',
            $outAbs,
        ], timeout: 300);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg не собрал Reels: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
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
