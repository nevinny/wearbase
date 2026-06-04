<?php

namespace App\Service\Gsc;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Search Console через Service Account (НЕ OAuth: один property, серверный крон).
 * Подключение: SA в Google Cloud → его email добавить в Search Console (Настройки →
 * Пользователи, право Full/Restricted) → JSON-ключ в GSC_CREDENTIALS_PATH.
 *
 * Fail-open: без кредов isConfigured()=false — синк логирует и выходит 0,
 * дрип-публикацию отсутствие GSC НЕ тормозит (правило дизайна).
 */
class GscClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private ?string $token = null;
    private float $tokenAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $credentialsPath,
        private readonly ?string $siteUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->credentialsPath) !== ''
            && is_file((string) $this->credentialsPath)
            && trim((string) $this->siteUrl) !== '';
    }

    /**
     * Search Analytics: дневные агрегаты по страницам (лаг GSC ~2-3 дня).
     *
     * @return array<int,array{page:string,date:string,impressions:int,clicks:int,position:float}>
     */
    public function searchAnalyticsByPage(\DateTimeInterface $from, \DateTimeInterface $to, int $rowLimit = 25000): array
    {
        $data = $this->post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode((string) $this->siteUrl) . '/searchAnalytics/query',
            [
                'startDate'  => $from->format('Y-m-d'),
                'endDate'    => $to->format('Y-m-d'),
                'dimensions' => ['page', 'date'],
                'rowLimit'   => $rowLimit,
            ],
        );

        $out = [];
        foreach (($data['rows'] ?? []) as $row) {
            $out[] = [
                'page'        => (string) ($row['keys'][0] ?? ''),
                'date'        => (string) ($row['keys'][1] ?? ''),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'position'    => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        return $out;
    }

    /**
     * URL Inspection (лимит Google 2000/день — квоту бережёт вызывающий).
     *
     * @return array{verdict:string,coverageState:?string,indexed:bool}
     */
    public function inspectUrl(string $url): array
    {
        $data = $this->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl'       => (string) $this->siteUrl,
        ]);

        $result  = $data['inspectionResult']['indexStatusResult'] ?? [];
        $verdict = (string) ($result['verdict'] ?? 'VERDICT_UNSPECIFIED');

        return [
            'verdict'       => $verdict,
            'coverageState' => isset($result['coverageState']) ? (string) $result['coverageState'] : null,
            'indexed'       => $verdict === 'PASS',
        ];
    }

    /** @return array<string,mixed> */
    private function post(string $url, array $body): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
            'json'    => $body,
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('GSC HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->toArray(false);
    }

    /** Токен SA с кэшем (~1ч жизни, обновляем за 5 мин до истечения). */
    private function accessToken(): string
    {
        $now = microtime(true);
        if ($this->token !== null && ($now - $this->tokenAt) < 3300) {
            return $this->token;
        }

        $creds = new ServiceAccountCredentials(self::SCOPE, (string) $this->credentialsPath);
        $token = $creds->fetchAuthToken();
        if (empty($token['access_token'])) {
            throw new \RuntimeException('GSC: не удалось получить access_token по Service Account');
        }

        $this->tokenAt = $now;

        return $this->token = (string) $token['access_token'];
    }
}
