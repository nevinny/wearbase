<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IndexNow-пинг о новых/обновлённых URL (мгновенная индексация). Протокол жрут
 * Яндекс и Bing (для wearbase.ru Яндекс критичен). Google IndexNow НЕ принимает:
 * для него легитимный сигнал — sitemap lastmod (уже работает) + ничего больше
 * (Indexing API для обычных страниц = ToS-нарушение, нам нельзя).
 *
 * Ключ: env INDEXNOW_KEY + файл public_html/{key}.txt (хостится на проде).
 * Fail-open: любые ошибки глотаются — пинг не должен ломать публикацию.
 */
class IndexNowPinger
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const HOST     = 'wearbase.ru';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $indexNowKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->indexNowKey) !== '';
    }

    /**
     * @param string[] $urls абсолютные URL (≤10000 за запрос по протоколу)
     * @return bool отправлено ли (false = не настроен/ошибка — публикацию не ломаем)
     */
    public function ping(array $urls): bool
    {
        if (!$this->isConfigured() || $urls === []) {
            return false;
        }

        try {
            $key = trim((string) $this->indexNowKey);
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'json' => [
                    'host'        => self::HOST,
                    'key'         => $key,
                    'keyLocation' => 'https://' . self::HOST . '/' . $key . '.txt',
                    'urlList'     => array_slice(array_values($urls), 0, 10000),
                ],
                'timeout' => 15,
            ]);

            // 200/202 = принято; остальное — молча false (fail-open)
            return in_array($response->getStatusCode(), [200, 202], true);
        } catch (\Throwable) {
            return false;
        }
    }
}
