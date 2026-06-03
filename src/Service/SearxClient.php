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
     */
    public function search(string $query, int $limit = 10, string $language = 'ru'): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($this->searxUrl, '/') . '/search', [
                'query'   => ['q' => $query, 'format' => 'json', 'language' => $language],
                'timeout' => 20,
            ]);
            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            return [];
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
}
