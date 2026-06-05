<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Вежливый fetch + очистка HTML→текст. Только статический HTML (RU-бренды
 * в основном Tilda/InSales/Bitrix — server-rendered). Защитно исключает
 * wearbase.ru на входе в fetch(), даже если фильтр забыли выше.
 */
class WebScraperService
{
    private const TIMEOUT = 15;
    private const MAX_BYTES = 2_000_000;      // 2 МБ — обрезаем большие страницы
    private const MAX_TEXT_CHARS = 12_000;    // лимит чистого текста (контекст/стоимость)
    private const NOISE_TAGS = 'script, style, nav, header, footer, noscript, svg, iframe, form, img, picture, source, button, aside, select, option';
    // То же, но БЕЗ form/select/option (там живёт размерная сетка) — для keepTables-режима.
    private const NOISE_TAGS_KEEP_TABLES = 'script, style, nav, header, footer, noscript, svg, iframe, img, picture, source, button, aside';
    private const MIN_LINE_CHARS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlFilter $urlFilter,
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; WearbaseBot/1.0)',
        private readonly string $trafilaturaBin = 'trafilatura',
    ) {
    }

    /**
     * Единая точка: URL → чистый текст. ПО УМОЛЧАНИЮ извлекаем через trafilatura
     * (качает + чистит основной контент, лучше DomCrawler); при недоступности
     * бинаря/сбое/пустом выводе — fallback на HttpClient + DomCrawler. Исключённые
     * домены (wearbase.ru) не качаются ни одним путём.
     */
    /**
     * @param bool $keepTables сохранять таблицы/select (размерные сетки!) — для
     *                         страниц /sizes и карточек товара. Обычная проза — false
     *                         (таблицы шумят эмбеддинги).
     */
    public function fetchCleanText(string $url, bool $keepTables = false): ?string
    {
        if ($this->urlFilter->isExcluded($url)) {
            return null;
        }

        if ($this->trafilaturaAvailable()) {
            $extracted = $this->runTrafilatura($url, $keepTables);
            if ($extracted !== null && trim($extracted) !== '') {
                return $this->normalizeText($extracted);
            }
            // пусто/ошибка — падаем на HTTP+DomCrawler
        }

        $page = $this->fetch($url);
        if ($page === null) {
            return null;
        }
        $text = $this->clean($page['html'], $keepTables);

        return $text !== '' ? $text : null;
    }

    /**
     * Доступна ли trafilatura. Абсолютный путь → проверяем is_executable
     * (на машине без неё — например Mac — сразу fallback, без лишнего спавна).
     * Голое имя → доверяем PATH (Process разрешит, при отсутствии поймаем сбой).
     */
    private function trafilaturaAvailable(): bool
    {
        $bin = $this->trafilaturaBin;
        if ($bin === '') {
            return false;
        }
        return str_contains($bin, '/') ? is_executable($bin) : true;
    }

    /** trafilatura -u URL: сам качает и извлекает основной текст. keepTables → не выкидывать таблицы (размеры). */
    private function runTrafilatura(string $url, bool $keepTables = false): ?string
    {
        // markdown сохраняет структуру таблиц текстом (| col | col |) — пригодно для LLM-extract.
        $args = $keepTables
            ? [$this->trafilaturaBin, '--no-comments', '--output-format', 'markdown', '-u', $url]
            : [$this->trafilaturaBin, '--no-comments', '--no-tables', '-u', $url];
        try {
            $proc = new Process($args);
            $proc->setTimeout(self::TIMEOUT + 15);
            $proc->run();

            return $proc->isSuccessful() ? $proc->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{url:string,httpStatus:int,html:string}|null null если исключён/не HTML/ошибка
     */
    public function fetch(string $url): ?array
    {
        // Защитный барьер: НИКОГДА не качаем исключённые домены (в т.ч. wearbase.ru).
        if ($this->urlFilter->isExcluded($url)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'      => ['User-Agent' => $this->userAgent],
                'timeout'      => self::TIMEOUT,
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                return ['url' => $url, 'httpStatus' => $status, 'html' => ''];
            }
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            if (stripos($contentType, 'text/html') === false) {
                return null;
            }

            // Обрезаем по объёму, не тянем гигантские страницы.
            $html = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $html .= $chunk->getContent();
                if (strlen($html) > self::MAX_BYTES) {
                    $response->cancel();
                    break;
                }
            }
        } catch (HttpExceptionInterface) {
            return null;
        }

        return ['url' => $url, 'httpStatus' => $status, 'html' => $html];
    }

    /** HTML → чистый текст: выкидываем шум, схлопываем пустые строки, обрезаем. */
    public function clean(string $html, bool $keepTables = false): string
    {
        if (trim($html) === '') {
            return '';
        }

        $crawler = new Crawler($html);

        $remove = [];
        foreach ($crawler->filter($keepTables ? self::NOISE_TAGS_KEEP_TABLES : self::NOISE_TAGS) as $node) {
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $crawler->filter('body');
        $raw = $body->count() > 0 ? $body->text(null, false) : $crawler->text(null, false);

        return $this->normalizeText($raw);
    }

    /**
     * Чистка текста: вырезаем base64/длинные хеши, построчно trim, дедуп строк
     * (меню/футеры повторяются и портят эмбеддинги), обрезаем по лимиту.
     */
    private function normalizeText(string $raw): string
    {
        $raw = preg_replace('/data:[^\s"\']{20,}/u', ' ', $raw);
        $raw = preg_replace('/\S{60,}/u', ' ', $raw);

        $lines = [];
        $seen = [];
        foreach (preg_split('/\R/u', $raw) as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
            if (mb_strlen($line) < self::MIN_LINE_CHARS) {
                continue;
            }
            $key = mb_strtolower($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = $line;
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_TEXT_CHARS);
    }

    /**
     * Достаёт соц-ссылки/контакты со страницы (для discovery и контакт-экстрактора).
     * Возвращает абсолютные URL, уже отфильтрованные UrlFilter.
     *
     * @return string[]
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $crawler = new Crawler($html);
        $found = [];
        foreach ($crawler->filter('a') as $a) {
            $href = $a->getAttribute('href');
            if ($href === '') {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs !== null && !$this->urlFilter->isExcluded($abs)) {
                $found[$abs] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Обнаружение внутренних страниц сайта для краула: sitemap.xml (включая
     * sitemap-index, рекурсивно 1 уровень) + ссылки с главной как fallback.
     * Возвращает абсолютные URL того же хоста, дедуп, без фильтрации ценности
     * (её делает CrawlUrlFilter у вызывающего). Прокси НЕ используется (сайт
     * бренда без анти-бота — ходим напрямую).
     *
     * @return string[]
     */
    public function discoverSitePages(string $siteUrl, int $hardCap = 300): array
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if ($host === '') {
            return [];
        }
        $scheme = parse_url($siteUrl, PHP_URL_SCHEME) ?: 'https';
        $root   = "{$scheme}://{$host}";

        $urls = [];
        // 1. sitemap.xml (+ один уровень sitemap-index)
        foreach ($this->fetchSitemapUrls($root . '/sitemap.xml', $hardCap) as $u) {
            $urls[$u] = true;
            if (count($urls) >= $hardCap) {
                break;
            }
        }

        // 2. Fallback / добор: ссылки с главной (для сайтов без sitemap)
        if (count($urls) < $hardCap) {
            $page = $this->fetch($siteUrl);
            if ($page !== null && $page['html'] !== '') {
                foreach ($this->extractLinks($page['html'], $siteUrl) as $u) {
                    if (strtolower((string) parse_url($u, PHP_URL_HOST)) === $host
                        || strtolower((string) parse_url($u, PHP_URL_HOST)) === 'www.' . $host) {
                        $urls[rtrim($u, '/')] = true;
                    }
                    if (count($urls) >= $hardCap) {
                        break;
                    }
                }
            }
        }

        unset($urls[rtrim($siteUrl, '/')]); // саму главную не дублируем (она уже own_site)

        return array_keys($urls);
    }

    /** @return string[] URL из sitemap; разворачивает sitemap-index на 1 уровень. */
    private function fetchSitemapUrls(string $sitemapUrl, int $hardCap): array
    {
        $page = $this->fetchXml($sitemapUrl);
        if ($page === null) {
            return [];
        }

        // sitemap-index → вложенные <sitemap><loc>
        if (stripos($page, '<sitemapindex') !== false) {
            $out = [];
            preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $page, $m);
            foreach (array_slice($m[1], 0, 10) as $childSitemap) {
                preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', (string) $this->fetchXml($childSitemap), $cm);
                foreach ($cm[1] as $u) {
                    $out[] = rtrim($u, '/');
                    if (count($out) >= $hardCap) {
                        return $out;
                    }
                }
            }
            return $out;
        }

        // обычный sitemap → <url><loc>
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $page, $m);

        return array_map(static fn(string $u) => rtrim($u, '/'), array_slice($m[1], 0, $hardCap));
    }

    /** Лёгкий GET XML (sitemap) без trafilatura. */
    private function fetchXml(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'       => ['User-Agent' => $this->userAgent],
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 3,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return mb_substr($response->getContent(false), 0, 3_000_000);
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            $parts = parse_url($baseUrl);
            if (!isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            return "{$parts['scheme']}://{$parts['host']}{$href}";
        }

        return null; // относительные/mailto/tel пропускаем
    }
}
