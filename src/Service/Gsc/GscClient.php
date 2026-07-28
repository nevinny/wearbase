<?php

namespace App\Service\Gsc;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Credentials\UserRefreshCredentials;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Search Console. GSC_CREDENTIALS_PATH принимает ДВА формата JSON:
 *  - "type":"service_account"  — ключ сервис-аккаунта (если орг-политика разрешает);
 *  - "type":"authorized_user"  — OAuth refresh-token (обход политики
 *    iam.disableServiceAccountKeyCreation; получается один раз через app:gsc:auth).
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
     * @return array<int,array{page:string,date:string,impressions:int,clicks:int,ctr:float,position:float}>
     */
    public function searchAnalyticsByPage(\DateTimeInterface $from, \DateTimeInterface $to, int $rowLimit = 25000): array
    {
        /** @var array<int,array{page:string,date:string,impressions:int,clicks:int,ctr:float,position:float}> $rows */
        $rows = $this->searchAnalytics(['page', 'date'], $from, $to, $rowLimit);

        return $rows;
    }

    /**
     * Search Analytics: дневные агрегаты по ТЕКСТУ ЗАПРОСА (второй pull, отдельный от
     * searchAnalyticsByPage — нужен для regex-свипа под AI Overviews, gsc_query_stats).
     *
     * @return array<int,array{query:string,date:string,impressions:int,clicks:int,ctr:float,position:float}>
     */
    public function searchAnalyticsByQuery(\DateTimeInterface $from, \DateTimeInterface $to, int $rowLimit = 25000): array
    {
        /** @var array<int,array{query:string,date:string,impressions:int,clicks:int,ctr:float,position:float}> $rows */
        $rows = $this->searchAnalytics(['query', 'date'], $from, $to, $rowLimit);

        return $rows;
    }

    /**
     * Search Analytics: срез запрос×страница ЗА ОКНО ЦЕЛИКОМ (без dimension `date`).
     * Дата опущена намеренно: единственный потребитель — «какой наш URL ранжируется по
     * этому запросу» (дожим позиций 4–10 в app:seo:gap-report и поиск двух URL на один
     * запрос). С date размер ответа растёт в разы, а суточная динамика для этой задачи
     * не нужна — она уже есть в gsc_page_stats/gsc_query_stats.
     *
     * @return array<int,array{query:string,page:string,impressions:int,clicks:int,ctr:float,position:float}>
     */
    public function searchAnalyticsByQueryPage(\DateTimeInterface $from, \DateTimeInterface $to, int $rowLimit = 25000): array
    {
        /** @var array<int,array{query:string,page:string,impressions:int,clicks:int,ctr:float,position:float}> $rows */
        $rows = $this->searchAnalytics(['query', 'page'], $from, $to, $rowLimit);

        return $rows;
    }

    /**
     * Единственная точка запроса Search Analytics — три публичных среза выше суть тонкие
     * обёртки над ней (различаются только набором dimensions).
     *
     * ПАГИНАЦИЯ (startRow): API отдаёт не более rowLimit строк за запрос, «все данные»
     * берутся страницами до первой короткой — рекомендованная Google техника
     * (developers.google.com/webmaster-tools/v1/how-tos/all-your-data). Сейчас мы далеко
     * от потолка (максимум ~1.7k строк против rowLimit=25000, замер 2026-07-28), лишних
     * запросов цикл не делает: короткая первая страница → выход. Но без цикла усечение
     * на 25000 было бы МОЛЧАЛИВЫМ, а тихо потерять строки хуже лишнего запроса.
     * Квота не мешает: 1200 QPM на сайт против наших 3 запросов в сутки.
     *
     * ⚠️ Срезы НЕ взаимозаменяемы: добавление dimension `query` заставляет GSC отбрасывать
     * часть данных («when you group by page and/or query, our system may drop some data»)
     * — замер на wearbase.ru показал −50.7% показов, если сводить query×page в page-агрегат.
     * Поэтому page-срез тянется отдельным запросом, а не выводится суммированием.
     *
     * @param  list<string> $dimensions
     * @return array<int,array<string,int|float|string>> строки: поля-размерности + метрики
     */
    private function searchAnalytics(array $dimensions, \DateTimeInterface $from, \DateTimeInterface $to, int $rowLimit): array
    {
        $rowLimit = max(1, min(25000, $rowLimit)); // документированный диапазон 1–25000
        $url      = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . rawurlencode((string) $this->siteUrl) . '/searchAnalytics/query';

        $out = [];
        for ($startRow = 0; ; $startRow += $rowLimit) {
            $data = $this->post($url, [
                'startDate'  => $from->format('Y-m-d'),
                'endDate'    => $to->format('Y-m-d'),
                'dimensions' => $dimensions,
                'rowLimit'   => $rowLimit,
                'startRow'   => $startRow,
            ]);
            $rows = $data['rows'] ?? [];

            foreach ($rows as $row) {
                $mapped = [];
                foreach ($dimensions as $i => $dimension) {
                    $mapped[$dimension] = (string) ($row['keys'][$i] ?? '');
                }
                $mapped['impressions'] = (int) ($row['impressions'] ?? 0);
                $mapped['clicks']      = (int) ($row['clicks'] ?? 0);
                $mapped['ctr']         = round((float) ($row['ctr'] ?? 0), 4);
                $mapped['position']    = round((float) ($row['position'] ?? 0), 1);
                $out[] = $mapped;
            }

            if (count($rows) < $rowLimit) {
                break; // короткая (в т.ч. пустая) страница = строки кончились
            }
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

    /**
     * Sitemaps API — агрегат покрытия без поштучной инспекции (масштабируется на любой объём).
     * ⚠️ Поле contents[].indexed Google давно не заполняет (deprecated, обычно 0) — берём
     * submitted как знаменатель, errors/warnings/lastDownloaded как health-сигнал.
     *
     * @return array<int,array{path:string,submitted:int,errors:int,warnings:int,lastDownloaded:?string,isPending:bool}>
     */
    public function listSitemaps(): array
    {
        $data = $this->get('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode((string) $this->siteUrl) . '/sitemaps');

        $out = [];
        foreach (($data['sitemap'] ?? []) as $sm) {
            $submitted = 0;
            foreach (($sm['contents'] ?? []) as $c) {
                $submitted += (int) ($c['submitted'] ?? 0);
            }
            $out[] = [
                'path'           => (string) ($sm['path'] ?? ''),
                'submitted'      => $submitted,
                'errors'         => (int) ($sm['errors'] ?? 0),
                'warnings'       => (int) ($sm['warnings'] ?? 0),
                'lastDownloaded' => isset($sm['lastDownloaded']) ? (string) $sm['lastDownloaded'] : null,
                'isPending'      => (bool) ($sm['isPending'] ?? false),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function get(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('GSC HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->toArray(false);
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

        $json  = json_decode((string) file_get_contents((string) $this->credentialsPath), true) ?: [];
        $creds = ($json['type'] ?? '') === 'authorized_user'
            ? new UserRefreshCredentials(self::SCOPE, (string) $this->credentialsPath)
            : new ServiceAccountCredentials(self::SCOPE, (string) $this->credentialsPath);
        $token = $creds->fetchAuthToken();
        if (empty($token['access_token'])) {
            throw new \RuntimeException('GSC: не удалось получить access_token (' . ($json['type'] ?? 'unknown') . ')');
        }

        $this->tokenAt = $now;

        return $this->token = (string) $token['access_token'];
    }
}
