<?php

namespace App\Service;

/**
 * Фильтр ценности внутренних страниц при крауле сайта бренда (отдельно от
 * UrlFilter — тот про доменные исключения). Цель: брать содержательные разделы
 * (о бренде/доставка/размеры/каталог), выкидывать мусор (корзина/чекаут/login/
 * фасеты-пагинация) и не разворачивать карточки товаров пачками.
 *
 * Дизайн: tasktracker «полный краул сайтов брендов».
 */
class CrawlUrlFilter
{
    /** Шумовые сегменты пути / query — в краул НЕ берём. */
    private const DROP = [
        '/cart', '/korzina', '/checkout', '/oformlenie', '/order',
        '/login', '/signin', '/account', '/lk', '/profile', '/wishlist', '/favorites', '/compare',
        '/search', '/poisk', '/auth', '/register', '/password',
        '/tag/', '/tags/', '/feed', '/rss', '/sitemap',
    ];

    /** Query-маркеры комбинаторного взрыва (фасеты/пагинация/сортировка). */
    private const DROP_QUERY = ['sort=', 'filter=', 'page=', 'per_page=', 'view=', 'utm_'];

    /** Расширения файлов — не страницы. */
    private const DROP_EXT = ['.pdf', '.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg', '.zip', '.rar', '.mp4', '.css', '.js', '.xml', '.ico'];

    /**
     * Ценные сегменты — приоритет в краул (сначала они, добиваем общими до cap'а).
     * Возвращаем оценку приоритета: 0 = ценный, 1 = обычный, -1 = выкинуть.
     */
    private const VALUABLE = [
        'about', 'o-brende', 'o-nas', 'o-kompanii', 'brand', 'history', 'istoriya',
        'delivery', 'dostavka', 'oplata', 'payment', 'shipping', 'return', 'vozvrat', 'garantiya',
        'size', 'razmer', 'sizes', 'razmernaya', 'tablica-razmerov',
        'catalog', 'katalog', 'shop', 'collection', 'kollekciya', 'kollekcii',
        'contact', 'kontakt', 'faq', 'voprosy', 'magaziny', 'stores', 'shops',
    ];

    /**
     * @param string $url абсолютный URL внутренней страницы
     * @param string $host хост own_site (для проверки «свой домен»)
     * @return int -1 = выкинуть, 0 = ценный (приоритет), 1 = обычный
     */
    public function rank(string $url, string $host): int
    {
        $parsedHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        // Только тот же сайт (www-вариант допускаем).
        if ($parsedHost !== $host && $parsedHost !== 'www.' . $host && 'www.' . $parsedHost !== $host) {
            return -1;
        }

        $path  = strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));

        foreach (self::DROP_EXT as $ext) {
            if (str_ends_with($path, $ext)) {
                return -1;
            }
        }
        foreach (self::DROP as $seg) {
            if (str_contains($path, $seg)) {
                return -1;
            }
        }
        foreach (self::DROP_QUERY as $q) {
            if (str_contains($query, $q)) {
                return -1;
            }
        }

        foreach (self::VALUABLE as $v) {
            if (str_contains($path, $v)) {
                return 0;
            }
        }

        return 1;
    }
}
