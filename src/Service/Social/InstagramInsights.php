<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Единственная ответственность: дёрнуть Instagram Graph API insights для одного медиа
 * и вернуть сырое сопоставление metric name → value. Не решает, что с этими значениями
 * делать (сохранять/агрегировать) — это дело вызывающего (SocialCollectMetricsCommand).
 *
 * Набор метрик зависит от типа медиа (проверено живьём): Reels отдают views +
 * ig_reels_avg_watch_time (просмотры/удержание), у карусели/картинки этих метрик не бывает
 * (Graph API вернёт ошибку, если запросить их не у Reels).
 */
class InstagramInsights
{
    private const API_BASE = 'https://graph.instagram.com/v22.0';

    private const METRICS_REELS = 'reach,likes,comments,shares,saved,views,total_interactions,ig_reels_avg_watch_time';
    private const METRICS_DEFAULT = 'reach,likes,comments,shares,saved,total_interactions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array<string, int> metric name => value
     */
    public function fetch(string $mediaId, bool $isReels, string $accessToken): array
    {
        $response = $this->httpClient->request('GET', self::API_BASE . "/{$mediaId}/insights", [
            'query' => [
                'metric'       => $isReels ? self::METRICS_REELS : self::METRICS_DEFAULT,
                'access_token' => $accessToken,
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray(false);
        $this->assertNoError($data);

        $result = [];
        foreach ($data['data'] ?? [] as $entry) {
            $name = $entry['name'] ?? null;
            $value = $entry['values'][0]['value'] ?? null;
            if ($name === null || $value === null) {
                continue;
            }
            $result[(string) $name] = (int) $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function assertNoError(array $data): void
    {
        if (!isset($data['error'])) {
            return;
        }

        $message = $data['error']['message'] ?? 'unknown';
        $code = $data['error']['code'] ?? null;
        $suffix = $code !== null ? " (code {$code})" : '';

        throw new \RuntimeException("IG insights error: {$message}{$suffix}");
    }
}
