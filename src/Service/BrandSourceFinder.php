<?php

namespace App\Service;

use App\Entity\Brand;

/**
 * Находит URL-источники бренда для скрейпа, дешевле→дороже:
 *  0) уже сохранённые ссылки бренда (BrandLink) — чаще всего сайт уже есть;
 *  1) SearXNG-поиск официального сайта;
 *  2) угадывание домена по slug.
 * Всё прогоняется через UrlFilter (wearbase.ru/маркетплейсы вон) и ранжируется
 * по статичным признакам (без лишних HTTP — живость проверит сам fetch).
 */
class BrandSourceFinder
{
    private const SOCIAL_HOSTS = ['instagram.com', 'vk.com', 't.me', 'telegram.me', 'youtube.com'];

    public function __construct(
        private readonly SearxClient $searx,
        private readonly UrlFilter $urlFilter,
    ) {
    }

    /**
     * @return string[] ранжированный список URL для скрейпа (официальный сайт раньше соцсетей)
     */
    public function discover(Brand $brand, int $max = 5): array
    {
        $title = (string) $brand->getTitle();
        $slug  = (string) $brand->getSlug();
        $scores = [];   // url => score

        // 0) Ссылки из БД (приоритет — website).
        foreach ($brand->getLinks() as $link) {
            $url = $link->getLinkUrl();
            if ($url === null || $this->urlFilter->isExcluded($url)) {
                continue;
            }
            $bonus = ($link->getLinkType() === 'website') ? 6 : 2;
            $scores[$url] = max($scores[$url] ?? 0, $this->scoreUrl($url, $slug) + $bonus);
        }

        // 1) SearXNG.
        if ($title !== '' && $this->searx->isConfigured()) {
            foreach ($this->searx->search("{$title} бренд одежды официальный сайт", 8) as $r) {
                $url = $r['url'];
                if ($this->urlFilter->isExcluded($url)) {
                    continue;
                }
                $scores[$url] = max($scores[$url] ?? 0, $this->scoreUrl($url, $slug));
            }
        }

        // 2) Угадывание домена по slug.
        if ($slug !== '') {
            foreach (["https://{$slug}.ru", "https://{$slug}.com", "https://{$slug}store.ru"] as $guess) {
                if (!$this->urlFilter->isExcluded($guess) && !isset($scores[$guess])) {
                    $scores[$guess] = $this->scoreUrl($guess, $slug);
                }
            }
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, $max);
    }

    /** Статичный скоринг кандидата: совпадение slug в хосте, RU-TLD, https, не-соцсеть. */
    private function scoreUrl(string $url, string $slug): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $score = 0;

        if ($slug !== '' && str_contains($host, str_replace('-', '', $slug))) {
            $score += 3;
        }
        if (str_ends_with($host, '.ru') || str_ends_with($host, '.рф') || str_ends_with($host, '.xn--p1ai')) {
            $score += 2;
        }
        if (str_starts_with($url, 'https://')) {
            $score += 1;
        }
        foreach (self::SOCIAL_HOSTS as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                $score -= 2; // соцсети полезны, но официальный сайт важнее
                break;
            }
        }

        return $score;
    }
}
