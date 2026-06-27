<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Brave Search API (web/search) — доп-источник discover. Настоящий API с ключом,
 * НЕ скрейп → не страдает от fingerprint-бана, как google/brave-движки в SearXNG.
 *
 *  GET https://api.search.brave.com/res/v1/web/search?q=...&count=N
 *  Auth: X-Subscription-Token: <ключ>
 *  ⚠️ Free-tier: 1000 запросов/МЕСЯЦ (сброс ежемесячно). Бюджет — BraveSearchMeter
 *     (fail-closed: при исчерпании квоты тихо возвращаем []).
 *
 * Интерфейс совместим с SearxClient/YandexSearchClient::search() — drop-in.
 */
class BraveSearchClient
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly ?BraveSearchMeter $meter = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /** Осталась ли месячная квота (для экономного гейта на стороне вызывающего). */
    public function allowed(): bool
    {
        return $this->meter === null || $this->meter->allowed();
    }

    /**
     * @return array<int,array{url:string,title:string,content:string}>
     */
    public function search(string $query, int $limit = 10): array
    {
        if (!$this->isConfigured() || trim($query) === '') {
            return [];
        }
        // Месячный потолок — fail-closed: до платной квоты не доходим.
        if ($this->meter !== null && !$this->meter->allowed()) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => [
                    'X-Subscription-Token' => $this->apiKey,
                    'Accept'               => 'application/json',
                ],
                'query' => [
                    'q'           => $query,
                    'count'       => max(1, min(20, $limit)),
                    'country'     => 'ru',
                    'search_lang' => 'ru',
                ],
                'timeout' => 20,
            ]);

            // Запрос инициирован → списывается из месячной квоты. Фиксируем расход.
            $this->meter?->record();

            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            return [];
        }

        $out = [];
        foreach (($data['web']['results'] ?? []) as $r) {
            $url = $r['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $out[] = [
                'url'     => $url,
                'title'   => strip_tags((string) ($r['title'] ?? '')),
                'content' => strip_tags((string) ($r['description'] ?? '')),
            ];
        }

        return $out;
    }
}
