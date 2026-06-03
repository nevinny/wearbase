<?php

namespace App\Service;

use App\Entity\Brand;

/**
 * Находит URL-источники бренда для скрейпа. Режим «корпус»: собирает до $max
 * результатов из интернета (несколько поисковых запросов через SearXNG) +
 * сиды (ссылки из БД, угадывание домена). Фильтрует по релевантности (имя
 * бренда в заголовке/сниппете — иначе для общих названий нахватаем мусора),
 * дедуп по URL, cap по хосту (чтобы не утонуть в 50 страницах одного домена).
 * Исключения (wearbase.ru) — через UrlFilter.
 */
class BrandSourceFinder
{
    private const MAX_PER_HOST    = 4;    // не больше N страниц с одного хоста
    private const PER_QUERY       = 20;   // результатов на поисковый запрос

    public function __construct(
        private readonly SearxClient $searx,
        private readonly UrlFilter $urlFilter,
    ) {
    }

    /**
     * @return string[] до $max URL (сиды раньше поискового корпуса)
     */
    public function discover(Brand $brand, int $max = 50): array
    {
        $title = trim((string) $brand->getTitle());
        $slug  = (string) $brand->getSlug();
        $city  = trim((string) $brand->getCity());

        $urls = [];          // url => true (упорядоченный дедуп)
        $perHost = [];        // host => count

        $add = function (?string $url) use (&$urls, &$perHost): void {
            if ($url === null) {
                return;
            }
            $url = rtrim(trim($url), '/');
            if ($url === '' || isset($urls[$url]) || $this->urlFilter->isExcluded($url)) {
                return;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                return;
            }
            if (($perHost[$host] ?? 0) >= self::MAX_PER_HOST) {
                return;
            }
            $perHost[$host] = ($perHost[$host] ?? 0) + 1;
            $urls[$url] = true;
        };

        // 1) Сиды: ссылки бренда из БД (сайт/соцсети) — приоритет.
        foreach ($brand->getLinks() as $link) {
            $add($link->getLinkUrl());
        }

        // 2) Угадывание официального домена по slug.
        if ($slug !== '') {
            $add("https://{$slug}.ru");
            $add("https://{$slug}.com");
        }

        // 3) Поисковый корпус (нужен SearXNG).
        if ($title !== '' && $this->searx->isConfigured()) {
            $queries = [
                "{$title} бренд одежды",
                "{$title} одежда отзывы",
                "{$title} купить одежда",
            ];
            if ($city !== '') {
                $queries[] = "{$title} {$city} магазин";
            }

            $needle = mb_strtolower($title);
            foreach ($queries as $q) {
                foreach ($this->searx->search($q, self::PER_QUERY) as $r) {
                    if ($this->relevant($needle, $slug, $r)) {
                        $add($r['url']);
                    }
                }
                if (count($urls) >= $max) {
                    break;
                }
            }
        }

        return array_slice(array_keys($urls), 0, $max);
    }

    /** Имя бренда (или slug) должно встречаться в результате — отсев нерелевантного. */
    private function relevant(string $needle, string $slug, array $r): bool
    {
        $hay = mb_strtolower(($r['title'] ?? '') . ' ' . ($r['content'] ?? '') . ' ' . ($r['url'] ?? ''));
        if ($needle !== '' && mb_strlen($needle) >= 3 && str_contains($hay, $needle)) {
            return true;
        }
        $slugN = str_replace('-', '', mb_strtolower($slug));

        return $slugN !== '' && mb_strlen($slugN) >= 3 && str_contains(str_replace('-', '', $hay), $slugN);
    }
}
