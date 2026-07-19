<?php

declare(strict_types=1);

namespace App\Service\Report;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сквозная конверсия карточки бренда (conversion-loop): поисковые клики на карточку
 * (gsc_page_stats, локальная БД, brand_id уже резолвлен по slug синком) → исходящие клики
 * на сайт бренда (/go/{id}, живут ТОЛЬКО на проде — реальные посетители там, не на dev).
 * Метрика КАЧЕСТВА МЕХАНИКИ карточки (не контента): rate = outgoing/incoming*100.
 *
 * Прод тянется по агент-API (/api/v1/outbound-click-stats, тот же паттерн, что
 * publish-stats/outreach-stats), матчинг dev↔прод по slug (id не совпадают).
 * Fail-safe: нет прод-URL/токена, прод недоступен, нет GSC-данных → available=false,
 * вызывающий код (дайджест/советник) просто пропускает секцию — ничего не выдумываем.
 */
final class CardConversionCalculator
{
    /** Ранжируем (топ/анти-топ) только карточки с этим минимумом входящих кликов — иначе шум. */
    private const MIN_INCOMING_FOR_RANKING = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
    ) {
    }

    /**
     * @return array{
     *   available: bool,
     *   reason?: string,
     *   this_week?: array{incoming:int,outgoing:int,rate:float},
     *   prev_week?: array{incoming:int,outgoing:int,rate:float},
     *   top?: list<array{brand_id:int,title:string,slug:string,incoming:int,outgoing:int,rate:float}>,
     *   bottom?: list<array{brand_id:int,title:string,slug:string,incoming:int,outgoing:int,rate:float}>,
     * }
     */
    public function compute(): array
    {
        $incoming = $this->fetchIncoming();
        if ($incoming === []) {
            return ['available' => false, 'reason' => 'нет данных gsc_page_stats за 14 дней'];
        }

        $outgoing = $this->fetchOutgoingFromProd();
        if ($outgoing === null) {
            return ['available' => false, 'reason' => 'прод /api/v1/outbound-click-stats недоступен'];
        }

        $brands = $this->fetchBrandMeta(array_keys($incoming));

        $rows = [];
        $totalIn = $totalOut = $totalInPrev = $totalOutPrev = 0;

        foreach ($incoming as $brandId => $inc) {
            $meta = $brands[$brandId] ?? null;
            if ($meta === null) {
                continue; // бренд с тех пор удалён/переименован локально — пропускаем
            }
            $slug = $meta['slug'];
            $out  = $outgoing[$slug] ?? ['this' => 0, 'prev' => 0];
            $in     = (int) $inc['this'];
            $inPrev = (int) $inc['prev'];
            $o      = (int) $out['this'];
            $oPrev  = (int) $out['prev'];

            if ($in > 0) {
                $totalIn  += $in;
                $totalOut += $o;
            }
            if ($inPrev > 0) {
                $totalInPrev  += $inPrev;
                $totalOutPrev += $oPrev;
            }

            if ($in >= self::MIN_INCOMING_FOR_RANKING) {
                $rows[] = [
                    'brand_id' => $brandId,
                    'title'    => (string) $meta['title'],
                    'slug'     => $slug,
                    'incoming' => $in,
                    'outgoing' => $o,
                    'rate'     => round(100 * $o / $in, 1),
                ];
            }
        }

        $byRateDesc = $rows;
        usort($byRateDesc, static fn(array $a, array $b) => $b['rate'] <=> $a['rate']);
        $byRateAsc = $rows;
        usort($byRateAsc, static fn(array $a, array $b) => $a['rate'] <=> $b['rate']);

        return [
            'available' => true,
            'this_week' => [
                'incoming' => $totalIn,
                'outgoing' => $totalOut,
                'rate'     => $totalIn > 0 ? round(100 * $totalOut / $totalIn, 2) : 0.0,
            ],
            'prev_week' => [
                'incoming' => $totalInPrev,
                'outgoing' => $totalOutPrev,
                'rate'     => $totalInPrev > 0 ? round(100 * $totalOutPrev / $totalInPrev, 2) : 0.0,
            ],
            'top'    => array_slice($byRateDesc, 0, 3),
            'bottom' => array_slice($byRateAsc, 0, 3),
        ];
    }

    /** @return array<int, array{this:int,prev:int}> brand_id => окна */
    private function fetchIncoming(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT brand_id,
                       SUM(CASE WHEN day >= CURDATE() - INTERVAL 7 DAY THEN clicks ELSE 0 END) AS clicks_this,
                       SUM(CASE WHEN day >= CURDATE() - INTERVAL 14 DAY
                                 AND day <  CURDATE() - INTERVAL 7 DAY THEN clicks ELSE 0 END) AS clicks_prev
                FROM gsc_page_stats
                WHERE brand_id IS NOT NULL AND day >= CURDATE() - INTERVAL 14 DAY
                GROUP BY brand_id
                HAVING clicks_this > 0 OR clicks_prev > 0
            SQL);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['brand_id']] = ['this' => (int) $r['clicks_this'], 'prev' => (int) $r['clicks_prev']];
        }

        return $out;
    }

    /** @param list<int> $brandIds @return array<int, array{slug:string,title:string}> */
    private function fetchBrandMeta(array $brandIds): array
    {
        if ($brandIds === []) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, title FROM brand WHERE id IN (?)',
            [$brandIds],
            [Connection::PARAM_INT_ARRAY],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['slug' => (string) $r['slug'], 'title' => (string) $r['title']];
        }

        return $out;
    }

    /** @return array<string, array{this:int,prev:int}>|null slug => окна; null — прод недоступен */
    private function fetchOutgoingFromProd(): ?array
    {
        if (trim((string) $this->prodApiUrl) === '') {
            return null;
        }
        try {
            $d = $this->httpClient->request(
                'GET',
                rtrim((string) $this->prodApiUrl, '/') . '/api/v1/outbound-click-stats',
                ['headers' => ['X-Agent-Token' => (string) $this->agentToken], 'timeout' => 8],
            )->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $out = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            if (!isset($item['slug'])) {
                continue;
            }
            $out[(string) $item['slug']] = [
                'this' => (int) ($item['clicks_this_week'] ?? 0),
                'prev' => (int) ($item['clicks_prev_week'] ?? 0),
            ];
        }

        return $out;
    }
}
