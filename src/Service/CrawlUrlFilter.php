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
    private const DROP_SEG = [
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
    // INFO — ТОЛЬКО информационные разделы (без catalog/shop — те идут в CATEGORY/PRODUCT по глубине).
    private const VALUABLE = [
        'about', 'o-brende', 'o-nas', 'o-kompanii', 'history', 'istoriya',
        'delivery', 'dostavka', 'oplata', 'payment', 'shipping', 'return', 'vozvrat', 'garantiya',
        'size', 'razmer', 'sizes', 'razmernaya', 'tablica-razmerov',
        'contact', 'kontakt', 'faq', 'voprosy', 'magaziny', 'stores',
    ];

    public const DROP          = 'drop';          // мусор — не берём
    public const INFO          = 'info';          // о бренде/доставка/размеры/контакты — высший приоритет
    public const CATEGORY      = 'category';       // страница каталога/коллекции (1-2 уровня)
    public const PRODUCT_CARD  = 'product_card';   // карточка товара — семплируем ≤N
    public const ORDINARY      = 'ordinary';       // прочая страница — добор до cap

    /** Сегменты карточки товара. */
    private const PRODUCT_SEG = ['/product/', '/tovar/', '/products/', '/tovary/', '/item/', '/goods/', '/p/'];
    /** Сегменты страницы категории/каталога. */
    private const CATEGORY_SEG = ['/catalog', '/katalog', '/collection', '/kollekci', '/shop', '/magazin', '/category', '/kategori'];

    /**
     * Классификация внутренней страницы для краула.
     *
     * @param string $url абсолютный URL
     * @param string $host хост own_site
     * @return string один из DROP|INFO|CATEGORY|PRODUCT_CARD|ORDINARY
     */
    public function classify(string $url, string $host): string
    {
        $parsedHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($parsedHost !== $host && $parsedHost !== 'www.' . $host && 'www.' . $parsedHost !== $host) {
            return self::DROP;
        }

        $path  = strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));

        foreach (self::DROP_EXT as $ext) {
            if (str_ends_with($path, $ext)) {
                return self::DROP;
            }
        }
        foreach (self::DROP_SEG as $seg) {
            if (str_contains($path, $seg)) {
                return self::DROP;
            }
        }
        foreach (self::DROP_QUERY as $q) {
            if (str_contains($query, $q)) {
                return self::DROP;
            }
        }

        $depth = substr_count(trim($path, '/'), '/') + ($path !== '/' && $path !== '' ? 1 : 0);

        // INFO — верхнеуровневые инфо-страницы (depth ≤2): иначе 'size' ловит 'oversize'
        // в глубоком URL карточки /catalog/hudi/oversize-123.
        if ($depth <= 2) {
            foreach (self::VALUABLE as $v) {
                if (str_contains($path, $v)) {
                    return self::INFO;
                }
            }
        }

        // Карточка товара: явный товарный сегмент, ЛИБО глубокий путь под каталогом (≥3 сегмента).
        foreach (self::PRODUCT_SEG as $seg) {
            if (str_contains($path, $seg)) {
                return self::PRODUCT_CARD;
            }
        }
        $underCatalog = false;
        foreach (self::CATEGORY_SEG as $seg) {
            if (str_contains($path, $seg)) {
                $underCatalog = true;
                break;
            }
        }
        if ($underCatalog) {
            // catalog/<cat> (1-2 уровня) = категория; catalog/<cat>/<товар> (≥3) = карточка
            return $depth >= 3 ? self::PRODUCT_CARD : self::CATEGORY;
        }

        return self::ORDINARY;
    }

    /** Совместимость со старым вызовом: -1 выкинуть, 0 ценный (INFO/CATEGORY), 1 обычный. */
    public function rank(string $url, string $host): int
    {
        return match ($this->classify($url, $host)) {
            self::DROP, self::PRODUCT_CARD => $this->classify($url, $host) === self::DROP ? -1 : 1,
            self::INFO, self::CATEGORY     => 0,
            default                        => 1,
        };
    }
}
