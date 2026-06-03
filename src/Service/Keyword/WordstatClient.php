<?php

namespace App\Service\Keyword;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Ключевики через Yandex Cloud Search API (Wordstat).
 * Auth: ключ формата AQVN… (Yandex Cloud Api-Key) → заголовок `Authorization: Api-Key <key>`.
 * Требует folderId (каталог Yandex Cloud). Если токен/folderId не заданы или запрос
 * упал — возвращает [] (вызывающий откатится на LLM-ключевики). Эндпоинт/формат
 * ответа Yandex Cloud Wordstat могут потребовать сверки при первом включении.
 *
 * @see https://yandex.cloud/en/docs/search-api/api-ref/Wordstat/
 */
class WordstatClient implements KeywordProviderInterface
{
    private const ENDPOINT = 'https://searchapi.api.cloud.yandex.net/v2/wordstat:getTopRequests';
    private const REGION_RUSSIA = 225;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $folderId,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->folderId) !== '';
    }

    public function keywordsFor(string $seed, int $limit = 8): array
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
                    'folderId' => $this->folderId,
                    'phrase'   => $seed,
                    'region'   => [self::REGION_RUSSIA],
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            return [];
        }

        // Ответ Yandex Cloud Wordstat: список фраз с частотами (структура может
        // отличаться по версии API — берём оборонительно несколько вероятных ключей).
        $rows = $data['topRequests'] ?? $data['requests'] ?? $data['items'] ?? [];
        $phrases = [];
        foreach ($rows as $row) {
            $phrase = is_array($row) ? ($row['phrase'] ?? $row['text'] ?? null) : (is_string($row) ? $row : null);
            if (is_string($phrase) && trim($phrase) !== '') {
                $phrases[] = trim($phrase);
            }
            if (count($phrases) >= $limit) {
                break;
            }
        }

        return $phrases;
    }
}
