<?php

namespace App\Service\Keyword;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Ключевики через Yandex Cloud Search API (Wordstat).
 *  POST https://searchapi.api.cloud.yandex.net/v2/wordstat/topRequests
 *  Auth: заголовок `Authorization: Api-Key <ключ>` (ключ формата AQVN…).
 *  Body: {phrase, region:[225], numPhrases}. folderId НЕ нужен — ключ привязан
 *        к каталогу сам.
 *  Resp: results[{phrase,count}] (включающие фразы → origin) +
 *        associations[{phrase,count}] (похожие → related). count = показов/мес.
 */
class WordstatClient implements KeywordProviderInterface
{
    private const ENDPOINT = 'https://searchapi.api.cloud.yandex.net/v2/wordstat/topRequests';
    private const REGION_RUSSIA = 225;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function keywordsFor(string $seed, int $limit = 30): array
    {
        if (!$this->isConfigured() || trim($seed) === '') {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'phrase'     => $seed,
                    'region'     => [self::REGION_RUSSIA],
                    'numPhrases' => max(1, min(2000, $limit)),
                ],
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
            // Часовая квота (100 запросов/час, gRPC code 8 RESOURCE_EXHAUSTED → 429):
            // отличаем от «нет результатов», чтобы вызывающий мог остановиться/подождать.
            if ($status === 429 || str_contains($body, 'quota limit exceed') || str_contains($body, 'RESOURCE_EXHAUSTED')) {
                throw new WordstatQuotaException('Wordstat hourly quota (100/час) exceeded');
            }
            if ($status >= 400) {
                return [];
            }
            $data = json_decode($body, true) ?: [];
        } catch (HttpExceptionInterface) {
            return [];
        }

        // results — включающие фразы (origin), associations — похожие (related).
        $out = [];
        $groups = [
            ['rows' => $data['results'] ?? [],      'type' => 'origin'],
            ['rows' => $data['associations'] ?? [], 'type' => 'related'],
        ];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $phrase = is_array($row) ? ($row['phrase'] ?? null) : null;
                if (!is_string($phrase) || trim($phrase) === '') {
                    continue;
                }
                $count = is_array($row) ? ($row['count'] ?? null) : null;
                $out[] = [
                    'keyword'      => trim($phrase),
                    'type'         => $group['type'],
                    'monthlyShows' => is_numeric($count) ? (int) $count : null,
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }
}
