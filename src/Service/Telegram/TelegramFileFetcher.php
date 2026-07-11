<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Скачивание файла из Telegram (getFile → download) во временный файл.
 * Только изображения, лимит 10 МБ. Любая ошибка → null + лог (диалог не падает).
 */
readonly class TelegramFileFetcher
{
    private const API_BASE = 'https://api.telegram.org';
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $botToken,
        private LoggerInterface $telegramLogger,
    ) {}

    /** @return string|null путь к временному файлу (caller отвечает за очистку) */
    public function fetchToTmp(string $fileId): ?string
    {
        if ($this->botToken === '' || $fileId === '') {
            return null;
        }

        try {
            $data = $this->httpClient->request('POST', self::API_BASE . '/bot' . $this->botToken . '/getFile', [
                'json' => ['file_id' => $fileId],
            ])->toArray(false);

            $filePath = $data['result']['file_path'] ?? null;
            $fileSize = (int) ($data['result']['file_size'] ?? 0);
            if (!($data['ok'] ?? false) || !is_string($filePath) || $filePath === '') {
                $this->telegramLogger->warning('TG getFile failed', ['file_id' => $fileId, 'response' => $data]);
                return null;
            }
            if ($fileSize > self::MAX_BYTES) {
                $this->telegramLogger->warning('TG file too large', ['file_id' => $fileId, 'size' => $fileSize]);
                return null;
            }

            $ext = mb_strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $this->telegramLogger->warning('TG file is not an image', ['file_id' => $fileId, 'file_path' => $filePath]);
                return null;
            }

            $content = $this->httpClient->request('GET', self::API_BASE . '/file/bot' . $this->botToken . '/' . $filePath)
                ->getContent();
            if ($content === '' || strlen($content) > self::MAX_BYTES) {
                $this->telegramLogger->warning('TG file download empty/too large', ['file_id' => $fileId, 'bytes' => strlen($content)]);
                return null;
            }

            $tmpPath = sys_get_temp_dir() . '/tg_wardrobe_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (file_put_contents($tmpPath, $content) === false) {
                return null;
            }

            // Проверка реального содержимого, не только расширения
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
            if (!str_starts_with($mime, 'image/')) {
                $this->telegramLogger->warning('TG file MIME is not image/*', ['file_id' => $fileId, 'mime' => $mime]);
                @unlink($tmpPath);
                return null;
            }

            return $tmpPath;
        } catch (\Throwable $e) {
            $this->telegramLogger->error('TG file fetch failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
