<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Поиск источников через self-hosted SearXNG (JSON API на LLM-сервере).
 * Бесплатно, без капчи/ключей, агрегирует Yandex/Google — релевантно для RU.
 */
class SearxClient
{
    /**
     * Canary: запрос, у которого результаты есть ВСЕГДА (пока жив хоть один движок).
     * Отличает «по нишевому запросу честно пусто» от «поиск лежит»: часть движков
     * перманентно в unresponsive (yandex parsing error), поэтому сам список
     * suspended-движков сигналом служить не может.
     */
    private const CANARY_QUERY  = 'одежда купить';
    private const CANARY_TTL_SEC = 120;

    private ?bool $canaryAlive = null;
    private float $canaryCheckedAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $searxUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->searxUrl) !== '';
    }

    /**
     * @return array<int,array{url:string,title:string,content:string}>
     *
     * @throws SearxUnavailableException SearXNG лежит (HTTP-ошибка или canary без
     *                                   результатов) — НЕ путать с честным пустым [].
     */
    public function search(string $query, int $limit = 10, string $language = 'ru'): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $data = $this->doSearch($query, $language);

        // 0 результатов — честная пустота или лежащий поиск? Решает canary:
        // молча вернуть [] при мёртвых движках = сжечь бренд пустым discovery.
        if (($data['results'] ?? []) === [] && !$this->canaryAlive()) {
            $engines = implode(', ', array_map(
                static fn($e) => is_array($e) ? implode(': ', $e) : (string) $e,
                array_slice($data['unresponsive_engines'] ?? [], 0, 5),
            ));
            throw new SearxUnavailableException("SearXNG: canary без результатов, движки лежат ({$engines})");
        }

        $out = [];
        foreach (($data['results'] ?? []) as $r) {
            $url = $r['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $out[] = [
                'url'     => $url,
                'title'   => (string) ($r['title'] ?? ''),
                'content' => (string) ($r['content'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Сырой запрос к SearXNG без canary-логики (используется и самим canary).
     *
     * @return array<string,mixed> декодированный JSON
     *
     * @throws SearxUnavailableException сам SearXNG недоступен (HTTP/сеть)
     */
    private function doSearch(string $query, string $language = 'ru'): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->searxUrl, '/') . '/search', [
                'query'   => ['q' => $query, 'format' => 'json', 'language' => $language],
                'timeout' => 35,
            ]);
            if ($response->getStatusCode() >= 400) {
                throw new SearxUnavailableException("SearXNG HTTP {$response->getStatusCode()}");
            }

            return $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new SearxUnavailableException('SearXNG недоступен: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Canary-запрос с кэшем на CANARY_TTL_SEC (движки умирают/оживают в течение прогона). */
    private function canaryAlive(): bool
    {
        $now = microtime(true);
        if ($this->canaryAlive !== null && ($now - $this->canaryCheckedAt) < self::CANARY_TTL_SEC) {
            return $this->canaryAlive;
        }

        $this->canaryCheckedAt = $now;

        try {
            $data = $this->doSearch(self::CANARY_QUERY);
        } catch (SearxUnavailableException) {
            return $this->canaryAlive = false;
        }

        return $this->canaryAlive = (($data['results'] ?? []) !== []);
    }
}
