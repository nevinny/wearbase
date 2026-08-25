<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * Нормализация URL для дедупликации: одинаковые ссылки из фида и из статьи
 * должны дать один хеш (регистр хоста, трекинг-параметры, fragment, слэш в конце).
 */
final class UrlNormalizer
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'utm_referrer', 'fbclid', 'gclid', 'yclid', 'from', 'ref', 'amp',
    ];

    public function normalize(string $url): string
    {
        $p = parse_url(trim($url));
        if ($p === false || !isset($p['host'])) {
            return trim($url);
        }

        $scheme = strtolower($p['scheme'] ?? 'https');
        $host = strtolower($p['host']);
        // www.X → X: издания сами не консистентны между фидом и сайтом
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        $path = $p['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            $path = rtrim($path, '/');
        }

        parse_str($p['query'] ?? '', $query);
        foreach (self::TRACKING_PARAMS as $param) {
            unset($query[$param]);
        }
        ksort($query);
        $qs = http_build_query($query);

        return $scheme . '://' . $host . $path . ($qs !== '' ? '?' . $qs : '');
    }

    /** Стабильный ключ дедупликации: guid, а если пуст — нормализованный URL. */
    public function guidHash(string $guid, string $link): string
    {
        $key = trim($guid) !== '' ? $this->normalize($guid) : $this->normalize($link);

        return hash('sha256', $key);
    }
}
