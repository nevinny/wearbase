<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageDownloaderService
{
    private const TIMEOUT = 10;
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const TARGET_DIR = 'images/products';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $publicDir,
    ) {}

    public function download(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT,
            ]);

            $content = $response->getContent();
            $contentLength = \strlen($content);

            if ($contentLength > self::MAX_SIZE) {
                $this->logger->warning('Image too large', ['url' => $url, 'size' => $contentLength]);
                return null;
            }

            $extension = $this->getExtension($url, $response->getHeaders()['content-type'][0] ?? '');
            $filename = Uuid::v4()->toRfc4122() . '.' . $extension;
            $targetDir = $this->publicDir . '/' . self::TARGET_DIR;

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            file_put_contents($targetDir . '/' . $filename, $content);

            return $filename;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to download image', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getExtension(string $url, string $contentType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        foreach ($map as $type => $ext) {
            if (str_contains($contentType, $type)) {
                return $ext;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? $ext : 'jpg';
    }
}
