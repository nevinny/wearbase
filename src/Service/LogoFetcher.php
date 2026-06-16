<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Скачивает и валидирует кандидата логотипа (URL → LogoExtractor), сохраняет в
 * public_html/images/logos (плоское хранение, см. vich_uploader.yaml). Валидация:
 * формат (png/jpg/webp/gif/svg), мин. сторона, защита от баннеров и битых файлов.
 */
class LogoFetcher
{
    private const TIMEOUT          = 15;
    private const MAX_BYTES        = 2_000_000; // 2 МБ
    private const MIN_SIDE_LOGO    = 120;       // мин. сторона «настоящего» лого
    private const MIN_SIDE_FAVICON = 48;        // мягкий fallback для favicon
    private const MAX_ASPECT       = 6.0;       // отсекаем вытянутые баннеры (>6:1)
    private const LOGO_SUBDIR      = '/public_html/images/logos';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlFilter $urlFilter,
        private readonly string $projectDir,
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; WearbaseBot/1.0)',
    ) {
    }

    /**
     * Скачивает кандидат, валидирует. Возвращает данные для сохранения или null
     * (мусор/мелкий/баннер/недоступен).
     *
     * @return array{bytes:string, ext:string, width:int, height:int}|null
     */
    public function download(string $url, bool $favicon = false): ?array
    {
        if ($this->urlFilter->isExcluded($url)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'       => ['User-Agent' => $this->userAgent],
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 5,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }
            $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');

            $bytes = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (strlen($bytes) > self::MAX_BYTES) {
                    $response->cancel();
                    return null; // подозрительно большой для лого
                }
            }
        } catch (HttpExceptionInterface) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        // SVG — вектор: размер не меряем (но проверяем сигнатуру от мусора)
        if (str_contains($contentType, 'svg') || $this->looksLikeSvg($bytes)) {
            return ['bytes' => $bytes, 'ext' => 'svg', 'width' => 0, 'height' => 0];
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        [$w, $h] = $info;
        $ext = $this->extFromMime($info['mime'] ?? '');
        if ($ext === null || $w < 1 || $h < 1) {
            return null;
        }

        $minSide = $favicon ? self::MIN_SIDE_FAVICON : self::MIN_SIDE_LOGO;
        if (min($w, $h) < $minSide) {
            return null;
        }
        // вытянутые картинки — это баннеры/обложки, не лого
        if (max($w, $h) / max(1, min($w, $h)) > self::MAX_ASPECT) {
            return null;
        }

        return ['bytes' => $bytes, 'ext' => $ext, 'width' => $w, 'height' => $h];
    }

    /**
     * Сохраняет байты в public_html/images/logos, возвращает имя файла (для brand.logo).
     * Имя детерминировано по содержимому — повторное сохранение того же лого не плодит дубли.
     */
    public function save(string $bytes, string $ext, int $brandId): string
    {
        $dir = $this->projectDir . self::LOGO_SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $name = sprintf('logo_%d_%s.%s', $brandId, substr(sha1($bytes), 0, 12), $ext);
        file_put_contents($dir . '/' . $name, $bytes);

        return $name;
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 512));

        return stripos($head, '<svg') !== false
            || (str_starts_with($head, '<?xml') && stripos($bytes, '<svg') !== false);
    }

    private function extFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };
    }
}
