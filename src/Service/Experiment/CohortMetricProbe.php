<?php

declare(strict_types=1);

namespace App\Service\Experiment;

use Doctrine\DBAL\Connection;

/**
 * Замер метрики когорты за окно [from, to) для diff-in-diff (docs/mechanic_experiments.md).
 *
 * Источники — cohort-атрибутируемые таблицы:
 *  - gsc_page_stats (показы/клики; дедуплена по (page_url, day), см. Version20260719_gsc_page_stats_dedup)
 *  - brand_outbound_click (клики /go/ по бренду)
 *
 * Когорта — JSON-дескриптор, резолвится в SQL-предикат (без обращения к таблице brand):
 *  - {kind: brand_parity, parity: 0|1}  → MOD(brand_id,2) = parity (50/50 holdout по чётности id)
 *  - {kind: brand_ids, ids: [..]}       → brand_id IN (..)
 *  - {kind: page_like, like: '%/style/%'} → page_url LIKE '..' (только gsc; outbound не атрибутируется)
 *
 * Метрика:
 *  - card_conversion = outbound / impressions (прокси конверсии карточки)
 *  - search_ctr      = clicks / impressions
 *  - outbound_clicks | clicks | impressions — сырые суммы
 * Деление на ноль → 0.0.
 */
final class CohortMetricProbe
{
    public const METRICS = ['card_conversion', 'search_ctr', 'outbound_clicks', 'clicks', 'impressions'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{kind?:string,parity?:int,ids?:array,like?:string} $cohort
     * @return array{value:float,impr:int,clicks:int,outbound:int}
     */
    public function measure(array $cohort, string $metric, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        [$gscWhere, $gscParams, $isBrandCohort] = $this->gscPredicate($cohort);

        $fromDay = $from->format('Y-m-d');
        $toDay   = $to->format('Y-m-d');

        // Отсутствие таблиц (env пуст в test) → нулевой замер, не падаем (baseline при --start
        // получится нулевым, evaluate всё равно защищён своим гейтом свежести).
        try {
            $agg = $this->db->fetchAssociative(
                "SELECT COALESCE(SUM(impressions),0) AS impr, COALESCE(SUM(clicks),0) AS clk
                 FROM gsc_page_stats
                 WHERE {$gscWhere} AND day >= :fromDay AND day < :toDay",
                $gscParams + ['fromDay' => $fromDay, 'toDay' => $toDay],
            ) ?: ['impr' => 0, 'clk' => 0];
        } catch (\Throwable) {
            return ['value' => 0.0, 'impr' => 0, 'clicks' => 0, 'outbound' => 0];
        }

        $impr   = (int) $agg['impr'];
        $clicks = (int) $agg['clk'];

        $outbound = 0;
        if ($isBrandCohort) {
            [$boWhere, $boParams] = $this->outboundPredicate($cohort);
            try {
                $outbound = (int) $this->db->fetchOne(
                    "SELECT COUNT(*) FROM brand_outbound_click
                     WHERE {$boWhere} AND created_at >= :fromDt AND created_at < :toDt",
                    $boParams + ['fromDt' => $from->format('Y-m-d H:i:s'), 'toDt' => $to->format('Y-m-d H:i:s')],
                );
            } catch (\Throwable) {
                $outbound = 0;
            }
        }

        $value = match ($metric) {
            'card_conversion' => $impr > 0 ? $outbound / $impr : 0.0,
            'search_ctr'      => $impr > 0 ? $clicks / $impr : 0.0,
            'outbound_clicks' => (float) $outbound,
            'clicks'          => (float) $clicks,
            'impressions'     => (float) $impr,
            default           => throw new \InvalidArgumentException("Неизвестная метрика: {$metric}"),
        };

        return ['value' => round($value, 6), 'impr' => $impr, 'clicks' => $clicks, 'outbound' => $outbound];
    }

    /**
     * @param array{kind?:string,parity?:int,ids?:array,like?:string} $cohort
     * @return array{0:string,1:array,2:bool} [where, params, isBrandCohort]
     */
    private function gscPredicate(array $cohort): array
    {
        return match ($cohort['kind'] ?? '') {
            'brand_parity' => ['brand_id IS NOT NULL AND MOD(brand_id, 2) = :parity', ['parity' => (int) ($cohort['parity'] ?? 0)], true],
            'brand_ids'    => $this->brandIdsPredicate($cohort['ids'] ?? []),
            'page_like'    => ['page_url LIKE :likePat', ['likePat' => (string) ($cohort['like'] ?? '')], false],
            default        => throw new \InvalidArgumentException('Неизвестный тип когорты: ' . ($cohort['kind'] ?? '—')),
        };
    }

    /** @return array{0:string,1:array,2:bool} */
    private function brandIdsPredicate(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return ['1 = 0', [], true]; // пустая когорта → пустой замер, а не вся таблица
        }
        $placeholders = implode(',', array_map(static fn(int $i) => (string) $i, $ids));

        return ["brand_id IN ({$placeholders})", [], true];
    }

    /**
     * @param array{kind?:string,parity?:int,ids?:array} $cohort
     * @return array{0:string,1:array}
     */
    private function outboundPredicate(array $cohort): array
    {
        return match ($cohort['kind'] ?? '') {
            'brand_parity' => ['MOD(brand_id, 2) = :parity', ['parity' => (int) ($cohort['parity'] ?? 0)]],
            'brand_ids'    => $this->outboundIdsPredicate($cohort['ids'] ?? []),
            default        => ['1 = 0', []],
        };
    }

    /** @return array{0:string,1:array} */
    private function outboundIdsPredicate(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return ['1 = 0', []];
        }

        return ['brand_id IN (' . implode(',', array_map(static fn(int $i) => (string) $i, $ids)) . ')', []];
    }
}
