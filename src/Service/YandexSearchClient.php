<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Официальный Yandex Search API v2 (Yandex Cloud) — синхронный web-поиск.
 * Замена HTML-скрейпу яндекса в SearXNG (тот даёт parsing error + бот-детект).
 *
 *  POST https://searchapi.api.cloud.yandex.net/v2/web/search
 *  Auth: `Authorization: Api-Key <ключ>` (ОТДЕЛЬНЫЙ ключ Yandex Cloud + folderId,
 *        НЕ Wordstat-ключ; сервис-аккаунту нужна роль search-api.webSearch.user).
 *  Body: {query:{searchType,queryText}, folderId, responseFormat:FORMAT_XML, groupSpec}.
 *  Resp: {rawData: base64(XML)} — XML формата Yandex (yandexsearch>...>doc).
 *  ⚠️ ПЛАТНЫЙ: тарификация по числу инициированных запросов/мес (~0.49 ₽/запрос днём,
 *     ночь дешевле). Учёт и дневной потолок — YandexSearchMeter.
 *
 * Интерфейс совместим с SearxClient::search() — drop-in доп-источник для discover.
 */
class YandexSearchClient
{
    private const ENDPOINT = 'https://searchapi.api.cloud.yandex.net/v2/web/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $folderId = '',
        private readonly ?YandexSearchMeter $meter = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->folderId) !== '';
    }

    /**
     * @return array<int,array{url:string,title:string,content:string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        if (!$this->isConfigured() || trim($query) === '') {
            return [];
        }
        // Дневной потолок расхода — fail-closed: до платного API не доходим.
        if ($this->meter !== null && !$this->meter->allowed()) {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'query' => [
                        'searchType' => 'SEARCH_TYPE_RU',
                        'queryText'  => $query,
                    ],
                    'folderId'       => $this->folderId,
                    'responseFormat' => 'FORMAT_XML',
                    'groupSpec'      => [
                        'groupMode'    => 'GROUP_MODE_FLAT',
                        'groupsOnPage' => max(1, min(100, $limit)),
                        'docsInGroup'  => 1,
                    ],
                ],
                'timeout' => 30,
            ]);

            // Запрос инициирован → тарифицируется Яндексом (даже при 4xx). Фиксируем расход.
            $this->meter?->record();

            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            return [];
        }

        $raw = $data['rawData'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $xml = base64_decode($raw, true);
        if ($xml === false || $xml === '') {
            return [];
        }

        return $this->parseDocs($xml, $limit);
    }

    /**
     * Парсинг Yandex-XML: //doc → url/title/headline(или passages).
     *
     * @return array<int,array{url:string,title:string,content:string}>
     */
    private function parseDocs(string $xml, int $limit): array
    {
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($sx === false) {
            return [];
        }

        $out = [];
        foreach ($sx->xpath('//doc') ?: [] as $doc) {
            $url = trim((string) $doc->url);
            if ($url === '') {
                continue;
            }
            $content = $this->nodeText($doc->headline);
            if ($content === '' && isset($doc->passages)) {
                $content = $this->nodeText($doc->passages);
            }
            $out[] = [
                'url'     => $url,
                'title'   => $this->nodeText($doc->title),
                'content' => $content,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Текст элемента с учётом подсветки <hlword> внутри title/headline. */
    private function nodeText(?\SimpleXMLElement $el): string
    {
        if ($el === null) {
            return '';
        }
        $xml = $el->asXML();

        return $xml === false ? '' : trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
