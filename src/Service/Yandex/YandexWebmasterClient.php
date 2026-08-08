<?php

namespace App\Service\Yandex;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Яндекс.Вебмастер API v4 (api.webmaster.yandex.net) — RU-аналог GSC.
 * Auth: один OAuth-токen в заголовке `Authorization: OAuth <token>` (без refresh-танца,
 * в отличие от Google). Токен из env YANDEX_WEBMASTER_API_KEY.
 *
 * user_id и host_id резолвятся через API и кэшируются в объекте:
 *  - GET /v4/user/                      → user_id
 *  - GET /v4/user/{uid}/hosts/          → находим наш host_id (verified, домен из YANDEX_WEBMASTER_HOST)
 *
 * Fail-open: без токена isConfigured()=false — синк логирует и выходит 0 (как GscClient).
 */
class YandexWebmasterClient
{
    private const BASE = 'https://api.webmaster.yandex.net/v4';

    /** Потолок строк на один запрос у search-queries/popular и query-analytics/list. */
    private const PAGE = 500;

    private ?int $userId = null;
    private ?string $hostId = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $apiKey,
        private readonly ?string $host,   // домен нашего сайта, напр. "wearbase.ru"
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->apiKey) !== '' && trim((string) $this->host) !== '';
    }

    /** ID пользователя (владельца токена). */
    public function userId(): int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }
        $data = $this->get(self::BASE . '/user/');

        return $this->userId = (int) ($data['user_id'] ?? 0);
    }

    /** host_id нашего верифицированного сайта (по домену YANDEX_WEBMASTER_HOST). */
    public function hostId(): string
    {
        if ($this->hostId !== null) {
            return $this->hostId;
        }
        $data   = $this->get(self::BASE . '/user/' . $this->userId() . '/hosts/');
        $needle = mb_strtolower(trim((string) $this->host));

        foreach (($data['hosts'] ?? []) as $h) {
            $url = mb_strtolower((string) ($h['ascii_host_url'] ?? $h['unicode_host_url'] ?? ''));
            if ($url !== '' && str_contains($url, $needle)) {
                return $this->hostId = (string) $h['host_id'];
            }
        }

        throw new \RuntimeException(sprintf('Яндекс.Вебмастер: хост «%s» не найден среди верифицированных.', $this->host));
    }

    /**
     * Примеры страниц, СЕЙЧАС находящихся в поиске Яндекса (аналог «проиндексировано»).
     * Пагинация offset/limit; тянем до cap. Coverage в Яндексе не заморожен как в GSC.
     *
     * @return array<int,array{url:string,title:?string,lastAccess:?string}>
     */
    public function urlsInSearch(int $cap = 5000): array
    {
        $base = self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/search-urls/in-search/samples';
        $out  = [];
        $offset = 0;
        $pageSize = 100;

        while (count($out) < $cap) {
            $data = $this->get($base, ['limit' => $pageSize, 'offset' => $offset]);
            $samples = $data['samples'] ?? [];
            if ($samples === []) {
                break;
            }
            foreach ($samples as $s) {
                $out[] = [
                    'url'        => (string) ($s['url'] ?? ''),
                    'title'      => isset($s['title']) ? (string) $s['title'] : null,
                    'lastAccess' => isset($s['last_access']) ? (string) $s['last_access'] : null,
                ];
            }
            if (count($samples) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }

        return array_slice($out, 0, $cap);
    }

    /**
     * Запросы за прошлую неделю с позицией — С ПАГИНАЦИЕЙ (API отдаёт максимум 500 за
     * запрос, а у нас их 2000+; без пагинации мы видели только четверть выдачи Яндекса
     * и «дожимать» было нечего). Аналог GSC Search Analytics по приоритетному RU-рынку.
     *
     * @return array<int,array{query:string,shows:int,clicks:int,position:float,dateFrom:?string,dateTo:?string}>
     */
    public function popularQueries(int $limit = 2000): array
    {
        $out    = [];
        $offset = 0;
        $from   = null;
        $to     = null;

        while (count($out) < $limit) {
            $batch = min(self::PAGE, $limit - count($out));
            // query_indicator должен повторяться (query_indicator=A&query_indicator=B). Symfony HttpClient
            // сериализует массив как query_indicator[0]=… → Яндекс его не распознаёт и отдаёт пустые
            // indicators. Поэтому собираем query-строку руками.
            $qs = http_build_query(['order_by' => 'TOTAL_SHOWS', 'limit' => $batch, 'offset' => $offset])
                . '&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&query_indicator=AVG_SHOW_POSITION';
            $data = $this->get(
                self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/search-queries/popular?' . $qs,
            );

            $from ??= isset($data['date_from']) ? substr((string) $data['date_from'], 0, 10) : null;
            $to   ??= isset($data['date_to']) ? substr((string) $data['date_to'], 0, 10) : null;

            $rows = $data['queries'] ?? [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $q) {
                $ind = $q['indicators'] ?? [];
                $out[] = [
                    'query'    => (string) ($q['query_text'] ?? ''),
                    'shows'    => (int) round((float) ($ind['TOTAL_SHOWS'] ?? 0)),
                    'clicks'   => (int) round((float) ($ind['TOTAL_CLICKS'] ?? 0)),
                    'position' => round((float) ($ind['AVG_SHOW_POSITION'] ?? 0), 1),
                    'dateFrom' => $from,
                    'dateTo'   => $to,
                ];
            }

            $offset += count($rows);
            if (count($rows) < $batch || $offset >= (int) ($data['count'] ?? 0)) {
                break;
            }
        }

        return $out;
    }

    /**
     * «Анализ запросов» (POST query-analytics/list) — единственный способ узнать, КАКОЙ наш
     * URL Яндекс показывает по запросу: у каждой строки есть popular_complementary_indicator
     * с URL. В search-queries/popular URL нет вообще, поэтому дожим по Яндексу без этого
     * эндпоинта не знал, что править.
     *
     * Позиции здесь НЕТ (поля только IMPRESSIONS/CLICKS/CTR/DEMAND по дням) — позиция
     * берётся из popularQueries(); связка двух источников идёт по тексту запроса.
     * Значения посуточные — суммируем по окну (impressions/clicks) и берём максимум
     * спроса (DEMAND — частотность запроса в Яндексе, не наш показатель).
     *
     * @return array<int,array{query:string,url:string,impressions:int,clicks:int,demand:int}>
     */
    public function queryAnalytics(int $limit = 2000): array
    {
        $out    = [];
        $offset = 0;

        while (count($out) < $limit) {
            $batch = min(self::PAGE, $limit - count($out));
            $data  = $this->post(
                self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/query-analytics/list',
                [
                    'offset'                => $offset,
                    'limit'                 => $batch,
                    'device_type_indicator' => 'ALL',
                    'text_indicator'        => 'QUERY',
                ],
            );

            $rows = $data['text_indicator_to_statistics'] ?? [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $query = (string) ($row['text_indicator']['value'] ?? '');
                $url   = (string) ($row['popular_complementary_indicator']['value'] ?? '');
                if ($query === '' || $url === '') {
                    continue;
                }

                $impressions = 0;
                $clicks      = 0;
                $demand      = 0;
                foreach (($row['statistics'] ?? []) as $stat) {
                    $value = (int) round((float) ($stat['value'] ?? 0));
                    match ((string) ($stat['field'] ?? '')) {
                        'IMPRESSIONS' => $impressions += $value,
                        'CLICKS'      => $clicks += $value,
                        'DEMAND'      => $demand = max($demand, $value),
                        default       => null,
                    };
                }

                $out[] = ['query' => $query, 'url' => $url, 'impressions' => $impressions, 'clicks' => $clicks, 'demand' => $demand];
            }

            $offset += count($rows);
            if (count($rows) < $batch || $offset >= (int) ($data['count'] ?? 0)) {
                break;
            }
        }

        return $out;
    }

    /**
     * Сводка по хосту: ИКС, страниц в поиске, исключено, счётчики проблем по важности.
     *
     * @return array{sqi:int,searchable:int,excluded:int,problems:array<string,int>}
     */
    public function siteSummary(): array
    {
        $data = $this->get(self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/summary');

        return [
            'sqi'        => (int) ($data['sqi'] ?? 0),
            'searchable' => (int) ($data['searchable_pages_count'] ?? 0),
            'excluded'   => (int) ($data['excluded_pages_count'] ?? 0),
            'problems'   => array_map('intval', (array) ($data['site_problems'] ?? [])),
        ];
    }

    /**
     * Диагностика хоста — здесь же живут НАРУШЕНИЯ (в т.ч. малополезный контент).
     * Возвращаем только проблемы в состоянии PRESENT: state=ABSENT значит «проверено и
     * не найдено», и алертить по ним нельзя (иначе постоянный ложный шум).
     *
     * @return array<int,array{code:string,severity:string,state:string}>
     */
    public function diagnostics(): array
    {
        $data = $this->get(self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/diagnostics');

        $out = [];
        foreach ((array) ($data['problems'] ?? []) as $code => $problem) {
            $state = (string) ($problem['state'] ?? '');
            if ($state !== 'PRESENT') {
                continue;
            }
            $out[] = ['code' => (string) $code, 'severity' => (string) ($problem['severity'] ?? ''), 'state' => $state];
        }

        return $out;
    }

    /**
     * Битые ВНУТРЕННИЕ ссылки по данным Яндекса — готовый список, за который не надо
     * платить краул-бюджетом (app:seo:tech-audit проверяет 404 сам, но с явным cap'ом).
     *
     * @return array<int,array{from:string,to:string,found:?string}>
     */
    public function brokenInternalLinks(int $cap = 500): array
    {
        $out    = [];
        $offset = 0;

        while (count($out) < $cap) {
            $batch = min(100, $cap - count($out));
            $data  = $this->get(
                self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/links/internal/broken/samples',
                ['offset' => $offset, 'limit' => $batch],
            );

            $rows = $data['links'] ?? [];
            if ($rows === []) {
                break;
            }
            foreach ($rows as $link) {
                $out[] = [
                    'from'  => (string) ($link['source_url'] ?? ''),
                    'to'    => (string) ($link['destination_url'] ?? ''),
                    'found' => isset($link['discovery_date']) ? substr((string) $link['discovery_date'], 0, 10) : null,
                ];
            }

            $offset += count($rows);
            if (count($rows) < $batch || $offset >= (int) ($data['count'] ?? 0)) {
                break;
            }
        }

        return $out;
    }

    /**
     * История числа страниц В ПОИСКЕ Яндекса по дням (search-urls/in-search/history).
     *
     * @return array<string,int> Y-m-d => количество
     */
    public function inSearchHistory(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $data = $this->get(
            self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/search-urls/in-search/history',
            ['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')],
        );
        $out = [];
        foreach (($data['history'] ?? []) as $r) {
            $out[substr((string) ($r['date'] ?? ''), 0, 10)] = (int) round((float) ($r['value'] ?? 0));
        }

        return $out;
    }

    /**
     * История суммарных показов/кликов по дням (search-queries/all/history).
     *
     * @return array{shows:array<string,int>,clicks:array<string,int>}
     */
    public function queryTotalsHistory(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        // query_indicator повторяется — собираем query-строку вручную (см. popularQueries).
        $qs = http_build_query(['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')])
            . '&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS';
        $data = $this->get(
            self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/search-queries/all/history?' . $qs,
        );
        $pick = function (string $key) use ($data): array {
            $out = [];
            foreach (($data['indicators'][$key] ?? []) as $r) {
                $out[substr((string) ($r['date'] ?? ''), 0, 10)] = (int) round((float) ($r['value'] ?? 0));
            }
            return $out;
        };

        return ['shows' => $pick('TOTAL_SHOWS'), 'clicks' => $pick('TOTAL_CLICKS')];
    }

    /** Остаток дневной квоты на переобход. @return array{daily:int,remaining:int} */
    public function recrawlQuota(): array
    {
        $data = $this->get(self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/recrawl/quota');

        return [
            'daily'     => (int) ($data['daily_quota'] ?? 0),
            'remaining' => (int) ($data['quota_remainder'] ?? 0),
        ];
    }

    /** Отправить URL на переобход. @return string task_id */
    public function submitRecrawl(string $url): string
    {
        $data = $this->post(
            self::BASE . '/user/' . $this->userId() . '/hosts/' . $this->hostId() . '/recrawl/queue',
            ['url' => $url],
        );

        return (string) ($data['task_id'] ?? '');
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function get(string $url, array $query = []): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'OAuth ' . $this->apiKey],
            'query'   => $query,
            'timeout' => 30,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Яндекс.Вебмастер HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->toArray(false);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function post(string $url, array $body): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Authorization' => 'OAuth ' . $this->apiKey],
            'json'    => $body,
            'timeout' => 30,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Яндекс.Вебмастер HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->toArray(false);
    }
}
