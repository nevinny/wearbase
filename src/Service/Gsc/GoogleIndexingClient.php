<?php

namespace App\Service\Gsc;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Credentials\UserRefreshCredentials;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Indexing API — ЕДИНСТВЕННЫЙ Google-канал индексации (anti-trifecta,
 * docs/seo_adoption_plan.md п.3). Тот же SA JSON, что и GscClient
 * (GSC_CREDENTIALS_PATH), но scope `indexing`. Квоту (≤200/день) и cooldown
 * (14 дней) бережёт вызывающий (app:google:index-ping).
 *
 * Fail-open: без кредов isConfigured()=false, publish() вернёт null —
 * на проде кредов нет, пинги уходят с Mac (как app:gsc:sync).
 */
class GoogleIndexingClient
{
    private const SCOPE    = 'https://www.googleapis.com/auth/indexing';
    private const ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    private ?string $token = null;
    private float $tokenAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $credentialsPath,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->credentialsPath) !== ''
            && is_file((string) $this->credentialsPath);
    }

    /**
     * Уведомляет Google об обновлении URL (type=URL_UPDATED).
     *
     * @return int|null HTTP-код ответа (200 = принято, 429/403 = квота) или null если не настроен
     */
    public function publish(string $url): ?int
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
            'json'    => ['url' => $url, 'type' => 'URL_UPDATED'],
            'timeout' => 30,
        ]);

        return $response->getStatusCode();
    }

    /** Токен с кэшем (~1ч жизни, обновляем за 5 мин до истечения) — паттерн GscClient. */
    private function accessToken(): string
    {
        $now = microtime(true);
        if ($this->token !== null && ($now - $this->tokenAt) < 3300) {
            return $this->token;
        }

        $json  = json_decode((string) file_get_contents((string) $this->credentialsPath), true) ?: [];
        $creds = ($json['type'] ?? '') === 'authorized_user'
            ? new UserRefreshCredentials(self::SCOPE, (string) $this->credentialsPath)
            : new ServiceAccountCredentials(self::SCOPE, (string) $this->credentialsPath);
        $token = $creds->fetchAuthToken();
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Indexing API: не удалось получить access_token (' . ($json['type'] ?? 'unknown') . ')');
        }

        $this->tokenAt = $now;

        return $this->token = (string) $token['access_token'];
    }
}
