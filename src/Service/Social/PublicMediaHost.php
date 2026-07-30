<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Instagram Graph API требует публичный URL картинки (не файл-аплоуд). Наш MediaRenderer
 * генерит PNG локально на Mac — этот сервис конвертит его в JPEG (IG не ест PNG с альфой
 * стабильно) и точечно rsync'ит на НЕ-РФ хост, откуда Graph API его и заберёт по HTTP.
 *
 * ⚠️ Картинку НЕЛЬЗЯ хостить на РФ-проде wearbase.ru: Meta-краулер не может скачать медиа
 * с РФ-хоста (error 9004/2207052), хотя curl с VPN-Mac отдаёт 200. Поэтому заливаем на
 * внешний хост (env IG_MEDIA_SSH_DEST / IG_MEDIA_PUBLIC_BASE). Картинка нужна Meta лишь
 * на время публикации — дальше IG хранит свою копию.
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
        #[Autowire('%env(default::IG_MEDIA_SSH_DEST)%')]
        private readonly ?string $sshDest = null,
        #[Autowire('%env(default::IG_MEDIA_PUBLIC_BASE)%')]
        private readonly ?string $publicBase = null,
    ) {
    }

    /**
     * PNG (локальный, абсолютный путь) → JPEG рядом → rsync на внешний хост → публичный URL.
     */
    public function publicJpegUrl(string $localAbsPath): string
    {
        $this->assertConfigured();
        $this->assertInsideProject($localAbsPath);

        $jpegPath = $this->toJpegPath($localAbsPath);
        if (!is_file($jpegPath)) {
            $this->convertToJpeg($localAbsPath, $jpegPath);
        }

        $this->uploadToHost($jpegPath);

        return rtrim((string) $this->publicBase, '/') . '/' . basename($jpegPath);
    }

    /**
     * Отдать файл как есть (без конвертации) через тот же внешний хост — для видео Reels:
     * Graph API так же требует публичный video_url, а mp4 переупаковывать не нужно.
     */
    public function publicUrl(string $localAbsPath): string
    {
        $this->assertConfigured();
        $this->assertInsideProject($localAbsPath);

        $this->uploadToHost($localAbsPath);

        return rtrim((string) $this->publicBase, '/') . '/' . basename($localAbsPath);
    }

    private function assertConfigured(): void
    {
        if (trim((string) $this->sshDest) === '' || trim((string) $this->publicBase) === '') {
            throw new \RuntimeException('IG_MEDIA_SSH_DEST/IG_MEDIA_PUBLIC_BASE не заданы — некуда заливать медиа для IG.');
        }
    }

    private function assertInsideProject(string $localAbsPath): void
    {
        if (!str_starts_with($localAbsPath, $this->projectDir)) {
            throw new \RuntimeException("Путь вне проекта: {$localAbsPath}");
        }
        if (!is_file($localAbsPath)) {
            throw new \RuntimeException("Файл не найден: {$localAbsPath}");
        }
    }

    private function toJpegPath(string $pngPath): string
    {
        // Меняем ЛЮБОЕ расширение на .jpg, не только .png: фото брендов (brand_image) уже
        // .jpg, и при замене только .png получался бы дубль foo.jpg.jpg рядом с оригиналом.
        // Для .jpg путь совпадает с исходным → is_file() выше пропустит конвертацию и мы
        // зальём оригинал как есть.
        return preg_replace('/\.[a-z0-9]+$/i', '', $pngPath) . '.jpg';
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

    private function uploadToHost(string $jpegPath): void
    {
        $rsync = $this->resolveRsyncBinary();

        $process = new Process([
            $rsync, '-az',
            '-e', 'ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20',
            $jpegPath, (string) $this->sshDest,
        ], timeout: 60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "rsync картинки на внешний хост не удался: " . trim($process->getErrorOutput() ?: $process->getOutput())
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
