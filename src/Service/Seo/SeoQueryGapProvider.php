<?php

declare(strict_types=1);

namespace App\Service\Seo;

use Doctrine\DBAL\Connection;

/**
 * Резолв кандидатных SEO-фраз реального спроса (показы + позиция + наш URL-владелец)
 * из yandex_query_stats/gsc_query_stats + группировка по интенту — вынесено из
 * `SeoGapReportCommand` (было приватными методами команды), чтобы переиспользовать
 * в `app:seo:competitor-scan` без дублирования SQL (docs/seo_competitor_content.md).
 *
 * Поведение бит-в-бит как было в команде: те же полосы позиций, тот же порядок
 * группировки интента, те же источники резолва our_url. `SeoGapReportCommand`
 * теперь только вызывает эти методы — сам больше не содержит SQL.
 *
 * `shows >= ?` явно типизирован ParameterType::INTEGER: на MySQL (прод) любой тип
 * биндинга даёт одинаковый результат (числовое сравнение всегда), но на SQLite
 * (тест-БД, CLAUDE.md) параметр без явного типа биндится как TEXT — а сравнение
 * TEXT-литерала с агрегатным выражением без column affinity (HAVING SUM(...) shows
 * >= '10') идёт по storage-class порядку (TEXT > INTEGER/REAL всегда), из-за чего
 * строки молча отфильтровываются. Явный тип убирает расхождение MySQL/SQLite.
 */
final class SeoQueryGapProvider
{
    /** RU-маркеры «замена/сравнение», не покрытые узким regex classifier'а (comparison там якорен на vs / сравн- / разниц-). */
    private const REPLACE_EXTRA_PATTERN = '/замен\w*|аналог\w*|\bэто\s+\p{L}/iu';

    /** Стартовый гео-список из реальных данных мониторинга (docs/yandex_ai_visibility_monitoring.md) — расширять по мере появления новых городов в gap-листе. */
    private const GEO_PATTERN = '/спб|санкт[- ]?петербург|петербург|москв/iu';

    /**
     * Полосы позиций. `min`/`max` — границы (min исключительно, max включительно; null = без границы).
     * Порядок важен: striking первым (дожим дешевле новой посадочной).
     */
    public const BAND_BOUNDS = [
        'striking' => ['min' => 3.0, 'max' => 10.0],
        'gap'      => ['min' => 10.0, 'max' => null],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AioQueryClassifier $classifier,
    ) {
    }

    /**
     * Порядок важен: striking идёт первым (дожим существующей страницы дешевле новой посадочной).
     *
     * @return list<string>
     */
    public function resolveBands(string $band): array
    {
        if ($band === 'both') {
            return array_keys(self::BAND_BOUNDS);
        }

        return isset(self::BAND_BOUNDS[$band]) ? [$band] : [];
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    public function fetchBandRows(string $band, string $source, int $minShows, int $limit): array
    {
        $rows = [];
        if ($source === 'yandex' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchYandexRows($band, $minShows, $limit));
        }
        if ($source === 'gsc' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchGscRows($band, $minShows, $limit));
        }

        return $rows;
    }

    /**
     * SQL-условие полосы: min исключительно, max включительно — так striking (3<pos≤10)
     * и gap (pos>10) не пересекаются и один запрос не попадает в обе полосы.
     */
    private function bandCondition(string $band, string $column): string
    {
        $meta = self::BAND_BOUNDS[$band];
        $cond = sprintf('%s > %.1f', $column, $meta['min']);
        if ($meta['max'] !== null) {
            $cond .= sprintf(' AND %s <= %.1f', $column, $meta['max']);
        }

        return $cond;
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    private function fetchYandexRows(string $band, int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query_text AS query, shows, position
                 FROM yandex_query_stats
                 WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)
                   AND ' . $this->bandCondition($band, 'position') . ' AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
                [\Doctrine\DBAL\ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return []; // таблица не создана / крон синка ещё не отработал
        }

        // search-queries/popular отдаёт запросы без URL, поэтому страница-владелец берётся
        // из yandex_query_page (POST query-analytics, пишет app:yandex:sync) — там позиции
        // нет, а URL есть; связка по тексту запроса.
        $pages = $this->resolveYandexPages(array_map(static fn (array $r) => (string) $r['query'], $data));

        return array_map(
            static fn (array $r) => [
                'query'    => (string) $r['query'],
                'shows'    => (int) $r['shows'],
                'position' => round((float) $r['position'], 1),
                'source'   => 'yandex',
                'page'     => $pages[(string) $r['query']] ?? null,
            ],
            $data,
        );
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    private function fetchGscRows(string $band, int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query, SUM(impressions) shows, AVG(position) position
                 FROM gsc_query_stats
                 GROUP BY query
                 HAVING ' . $this->bandCondition($band, 'position') . ' AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
                [\Doctrine\DBAL\ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        $pages = $this->resolveGscPages(array_map(static fn (array $r) => (string) $r['query'], $data));

        return array_map(
            static fn (array $r) => [
                'query'    => (string) $r['query'],
                'shows'    => (int) $r['shows'],
                'position' => round((float) $r['position'], 1),
                'source'   => 'gsc',
                'page'     => $pages[(string) $r['query']] ?? null,
            ],
            $data,
        );
    }

    /**
     * То же для Яндекса — из yandex_query_page (пишет app:yandex:sync). Отдаёт путь без
     * домена (так его возвращает Вебмастер), в отличие от GSC с абсолютным URL.
     *
     * @param list<string> $queries
     * @return array<string,string> запрос → путь
     */
    public function resolveYandexPages(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT query, page_url, impressions AS shows
                 FROM yandex_query_page
                 WHERE query IN (?)
                 ORDER BY shows DESC',
                [$queries],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return [];
        }

        $pages = [];
        foreach ($rows as $r) {
            $pages[(string) $r['query']] ??= (string) $r['page_url'];
        }

        return $pages;
    }

    /**
     * Страница-владелец запроса — из gsc_query_page (срез query×page, пишет app:gsc:sync).
     * Берём URL с наибольшими показами: именно его и надо дожимать в полосе striking.
     * Пусто → '—' в отчёте: значит синк ещё не приносил этот срез (fail-open, не ошибка).
     *
     * @param list<string> $queries
     * @return array<string,string> запрос → URL
     */
    public function resolveGscPages(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT query, page_url, impressions AS shows
                 FROM gsc_query_page
                 WHERE query IN (?)
                 ORDER BY shows DESC',
                [$queries],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return [];
        }

        $pages = [];
        foreach ($rows as $r) {
            // ORDER BY shows DESC → первая строка на запрос и есть главная страница
            $pages[(string) $r['query']] ??= (string) $r['page_url'];
        }

        return $pages;
    }

    /**
     * Группировка запроса по интенту (порядок = приоритет матча):
     * brand_entity / replace_comparison / geo_category / navigation / other.
     *
     * @param list<string> $brandNames lowercase title+slug опубликованных брендов (см. fetchPublishedBrandNames)
     */
    public function classifyGroup(string $query, array $brandNames): string
    {
        $intent = $this->classifier->classify($query)['name'];
        if ($intent === 'brand_entity') {
            return 'brand_entity';
        }
        if ($intent === 'comparison' || preg_match(self::REPLACE_EXTRA_PATTERN, $query) === 1) {
            return 'replace_comparison';
        }
        if (preg_match(self::GEO_PATTERN, $query) === 1) {
            return 'geo_category';
        }
        if ($this->matchesKnownBrand($query, $brandNames)) {
            return 'navigation';
        }

        return 'other';
    }

    public function matchesKnownBrand(string $query, array $brandNames): bool
    {
        $q = mb_strtolower($query);
        foreach ($brandNames as $name) {
            if (mb_strlen($name) >= 3 && mb_stripos($q, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> lowercase title+slug опубликованных брендов, для навигационного матча. */
    public function fetchPublishedBrandNames(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT title, slug FROM brand WHERE status = 'active' AND published_at IS NOT NULL",
        );

        $names = [];
        foreach ($rows as $r) {
            if (!empty($r['title'])) {
                $names[] = mb_strtolower((string) $r['title']);
            }
            if (!empty($r['slug'])) {
                $names[] = mb_strtolower((string) $r['slug']);
            }
        }

        return array_values(array_unique($names));
    }
}
