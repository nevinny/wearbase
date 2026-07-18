<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Instagram Graph API требует публичный URL картинки (не файл-аплоуд). Наш MediaRenderer
 * генерит PNG локально на Mac — этот сервис конвертит его в JPEG (IG не ест PNG с альфой
 * стабильно) и точечно rsync'ит на прод, откуда Graph API его и заберёт по HTTP.
 *
 * ⚠️ cron PATH пуст — rsync ищем по фиксированным абсолютным путям, не полагаясь на PATH.
 */
class PublicMediaHost
{
    private const RSYNC_CANDIDATES = [
        '/opt/homebrew/bin/rsync',
        '/usr/local/bin/rsync',
        '/usr/bin/rsync',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * PNG (локальный, абсолютный путь) → JPEG рядом → rsync на прод → публичный HTTPS-URL.
     */
    public function publicJpegUrl(string $localAbsPath): string
    {
        if (!str_starts_with($localAbsPath, $this->projectDir)) {
            throw new \RuntimeException("Путь вне проекта: {$localAbsPath}");
        }
        if (!is_file($localAbsPath)) {
            throw new \RuntimeException("Файл не найден: {$localAbsPath}");
        }

        $jpegPath = $this->toJpegPath($localAbsPath);
        if (!is_file($jpegPath)) {
            $this->convertToJpeg($localAbsPath, $jpegPath);
        }

        $this->uploadToProd($jpegPath);

        return 'https://wearbase.ru/images/social/' . basename($jpegPath);
    }

    private function toJpegPath(string $pngPath): string
    {
        return preg_replace('/\.png$/i', '', $pngPath) . '.jpg';
    }

    private function convertToJpeg(string $pngPath, string $jpegPath): void
    {
        // ⚠️ Файлы с расширением .png в public_html/images/social/ не всегда настоящий PNG:
        // MediaRenderer иногда пишет туда сырые байты ответа AI-провайдера (Gemini/Cloudflare),
        // которые фактически JPEG. imagecreatefromstring() определяет формат по содержимому,
        // а не по расширению — читает оба случая.
        $bytes = @file_get_contents($pngPath);
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($src === false) {
            throw new \RuntimeException("Не удалось прочитать изображение: {$pngPath}");
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $flat = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $src, 0, 0, 0, 0, $width, $height);
        imagedestroy($src);

        $ok = imagejpeg($flat, $jpegPath, 85);
        imagedestroy($flat);

        if (!$ok) {
            throw new \RuntimeException("Не удалось сохранить JPEG: {$jpegPath}");
        }
    }

    private function uploadToProd(string $jpegPath): void
    {
        $rsync = $this->resolveRsyncBinary();

        $process = new Process([
            $rsync, '-az', $jpegPath, 'regru:wearbase.ru/public_html/images/social/',
        ], timeout: 60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "rsync на прод не удался: " . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function resolveRsyncBinary(): string
    {
        foreach (self::RSYNC_CANDIDATES as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('rsync не найден ни по одному из известных путей.');
    }
}
