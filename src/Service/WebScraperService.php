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
    private const MIN_LINE_CHARS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlFilter $urlFilter,
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; WearbaseBot/1.0)',
        private readonly string $trafilaturaBin = '',
    ) {
    }

    /**
     * Единая точка: URL → чистый текст. Если задан TRAFILATURA_BIN — извлекаем
     * через trafilatura (качает + чистит основной контент, лучше DomCrawler);
     * иначе/при сбое — fallback на HttpClient + DomCrawler. Исключённые домены
     * (wearbase.ru) не качаются ни одним путём.
     */
    public function fetchCleanText(string $url): ?string
    {
        if ($this->urlFilter->isExcluded($url)) {
            return null;
        }

        if ($this->trafilaturaBin !== '') {
            $extracted = $this->runTrafilatura($url);
            if ($extracted !== null && trim($extracted) !== '') {
                return $this->normalizeText($extracted);
            }
            // пусто/ошибка — падаем на HTTP+DomCrawler
        }

        $page = $this->fetch($url);
        if ($page === null) {
            return null;
        }
        $text = $this->clean($page['html']);

        return $text !== '' ? $text : null;
    }

    /** trafilatura -u URL: сам качает и извлекает основной текст. */
    private function runTrafilatura(string $url): ?string
    {
        try {
            $proc = new Process([$this->trafilaturaBin, '--no-comments', '--no-tables', '-u', $url]);
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
    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $crawler = new Crawler($html);

        $remove = [];
        foreach ($crawler->filter(self::NOISE_TAGS) as $node) {
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
