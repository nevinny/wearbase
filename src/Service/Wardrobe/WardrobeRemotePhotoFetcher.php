<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\WardrobeItem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WardrobeRemotePhotoFetcher
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function attachWildberriesPhoto(WardrobeItem $item, ?string $imageUrl): bool
    {
        $imageUrl = trim((string) $imageUrl);
        if ($imageUrl === '' || !$this->isAllowedUrl($imageUrl)) {
            return false;
        }

        $tmpPath = null;
        try {
            $response = $this->httpClient->request('GET', $imageUrl, [
                'max_redirects' => 0,
                'timeout' => 12,
                'headers' => ['Accept' => 'image/webp,image/jpeg,image/png'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $declaredLength = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if ($declaredLength > self::MAX_BYTES) {
                return false;
            }

            $content = $response->getContent();
            if ($content === '' || strlen($content) > self::MAX_BYTES) {
                return false;
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'wearbase-wb-');
            if ($tmpPath === false || file_put_contents($tmpPath, $content) === false) {
                return false;
            }

            $mime = MimeTypes::getDefault()->guessMimeType($tmpPath);
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                return false;
            }

            $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'jpg';
            $imagePath = $tmpPath . '.' . $extension;
            if (!rename($tmpPath, $imagePath)) {
                return false;
            }
            $tmpPath = $imagePath;

            $item->setPhotoFile(new UploadedFile(
                $imagePath,
                'wildberries.' . $extension,
                $mime,
                null,
                true,
            ));
            $tmpPath = null; // VichUploader переместит файл при flush.

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            if ($tmpPath !== null && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'wbbasket.ru' || str_ends_with($host, '.wbbasket.ru'));
    }
}
