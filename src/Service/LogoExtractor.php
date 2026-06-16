<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Извлекает URL-кандидаты логотипа из HTML страницы бренда (own_site / маркетплейс).
 * Только парсинг + скоринг «логотипности»; скачивание и валидация (формат/размер) —
 * на LogoFetcher. Источники в порядке убывания авторитетности:
 *   JSON-LD Organization.logo → og:logo → header <img> с «logo» → apple-touch-icon
 *   → og:image (часто соц-баннер) → favicon (последний шанс).
 */
class LogoExtractor
{
    private const SCORE_JSONLD_LOGO = 100; // schema.org Organization.logo — авторитетнее всего
    private const SCORE_OG_LOGO     = 90;
    private const SCORE_HEADER_IMG  = 80;  // <img> с «logo» в src/alt/class/id
    private const SCORE_APPLE_TOUCH = 60;  // apple-touch-icon (обычно 120-180px, годный fallback)
    private const SCORE_OG_IMAGE    = 40;  // часто баннер для соцсетей, а не лого
    private const SCORE_FAVICON     = 20;  // мелкий значок — мягкий fallback

    /**
     * @return list<array{url:string, score:int, source:string, favicon:bool}>
     *         дедуп по URL (берётся макс. score), отсортировано по score DESC
     */
    public function extract(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $crawler = new Crawler($html);
        $cands = [];

        // 1. JSON-LD Organization.logo (string | {url} | ImageObject)
        foreach ($this->jsonLdLogos($crawler) as $url) {
            $cands[] = ['url' => $url, 'score' => self::SCORE_JSONLD_LOGO, 'source' => 'jsonld', 'favicon' => false];
        }

        // 2. og:logo / og:image / twitter:image
        foreach ($crawler->filter('meta[property], meta[name]') as $m) {
            $prop    = strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
            $content = trim($m->getAttribute('content'));
            if ($content === '') {
                continue;
            }
            if ($prop === 'og:logo') {
                $cands[] = ['url' => $content, 'score' => self::SCORE_OG_LOGO, 'source' => 'og:logo', 'favicon' => false];
            } elseif (in_array($prop, ['og:image', 'og:image:url', 'twitter:image', 'twitter:image:src'], true)) {
                $cands[] = ['url' => $content, 'score' => self::SCORE_OG_IMAGE, 'source' => 'og:image', 'favicon' => false];
            }
        }

        // 3. <img> с «logo» в признаках (src/alt/class/id) — типичный логотип в шапке
        foreach ($crawler->filter('img') as $img) {
            $src = trim($img->getAttribute('src') ?: $img->getAttribute('data-src'));
            if ($src === '') {
                continue;
            }
            $hint = strtolower($src . ' ' . $img->getAttribute('alt') . ' '
                . $img->getAttribute('class') . ' ' . $img->getAttribute('id'));
            if (str_contains($hint, 'logo')) {
                $cands[] = ['url' => $src, 'score' => self::SCORE_HEADER_IMG, 'source' => 'img-logo', 'favicon' => false];
            }
        }

        // 4. apple-touch-icon (не favicon-порог) и favicon (мягкий порог)
        foreach ($crawler->filter('link[rel]') as $link) {
            $rel  = strtolower($link->getAttribute('rel'));
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            if (str_contains($rel, 'apple-touch-icon')) {
                $cands[] = ['url' => $href, 'score' => self::SCORE_APPLE_TOUCH, 'source' => 'apple-touch-icon', 'favicon' => false];
            } elseif ($rel === 'icon' || $rel === 'shortcut icon') {
                $cands[] = ['url' => $href, 'score' => self::SCORE_FAVICON, 'source' => 'favicon', 'favicon' => true];
            }
        }

        // Абсолютизировать, дедуп по URL (макс. score), сортировка
        $byUrl = [];
        foreach ($cands as $c) {
            $abs = $this->absolutize($c['url'], $baseUrl);
            if ($abs === null) {
                continue;
            }
            $c['url'] = $abs;
            if (!isset($byUrl[$abs]) || $c['score'] > $byUrl[$abs]['score']) {
                $byUrl[$abs] = $c;
            }
        }

        $out = array_values($byUrl);
        usort($out, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * Достаёт значения "logo" из всех <script type="application/ld+json">.
     * Рекурсивно обходит структуру (@graph, вложенные объекты).
     *
     * @return list<string>
     */
    private function jsonLdLogos(Crawler $crawler): array
    {
        $logos = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $data = json_decode(trim($node->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            $this->collectLogos($data, $logos);
        }

        return array_values(array_unique($logos));
    }

    /** @param array<mixed> $data */
    private function collectLogos(array $data, array &$out): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'logo') {
                if (is_string($value) && $value !== '') {
                    $out[] = $value;
                } elseif (is_array($value)) {
                    // {"@type":"ImageObject","url":"..."} либо ["url1","url2"]
                    if (isset($value['url']) && is_string($value['url'])) {
                        $out[] = $value['url'];
                    } else {
                        foreach ($value as $v) {
                            if (is_string($v) && $v !== '') {
                                $out[] = $v;
                            }
                        }
                    }
                }
            } elseif (is_array($value)) {
                $this->collectLogos($value, $out);
            }
        }
    }

    /**
     * Абсолютизирует href относительно baseUrl. Понимает абсолютные, //, /path
     * и относительные пути (резолв от директории baseUrl). data:/пустые → null.
     */
    private function absolutize(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'data:')) {
            return null;
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        $parts = parse_url($baseUrl);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $root = "{$parts['scheme']}://{$parts['host']}";

        if (str_starts_with($href, '/')) {
            return $root . $href;
        }

        // относительный путь — резолвим от директории base-URL
        $path = $parts['path'] ?? '/';
        $slash = strrpos($path, '/');
        $dir = $slash !== false ? substr($path, 0, $slash + 1) : '/';

        return $root . $dir . $href;
    }
}
