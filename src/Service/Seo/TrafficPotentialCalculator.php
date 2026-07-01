<?php

namespace App\Service\Seo;

use Doctrine\DBAL\Connection;

/**
 * Потенциал трафика Яндекса: сколько кликов ПОЛУЧАЕМ (captured) vs МОГЛИ БЫ при топ-3.
 * Трафик = клики (не CTR); потенциал = показы × CTR(целевая позиция). Вход: yandex_query_stats
 * (запросы, где ранжируемся) + brand_keyword (Wordstat-спрос = адресуемый рынок).
 * Общий для команды app:seo:traffic-potential и админ-панели.
 */
class TrafficPotentialCalculator
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Кривая CTR по позиции в Яндексе (приближение). */
    public function ctr(float $pos): float
    {
        $p = (int) round($pos);
        return match (true) {
            $p <= 1  => 0.28,
            $p === 2 => 0.15,
            $p === 3 => 0.10,
            $p === 4 => 0.07,
            $p === 5 => 0.05,
            $p === 6 => 0.04,
            $p === 7 => 0.03,
            $p === 8 => 0.025,
            $p === 9 => 0.02,
            $p <= 10 => 0.018,
            $p <= 20 => 0.008,
            default  => 0.003,
        };
    }

    /**
     * @return array{window:string,queries:int,captured:int,potentialTop3:int,missed:int,
     *               factor:float,demand:int,addressable:int,
     *               opportunities:list<array{q:string,shows:int,pos:float,now:float,top3:float,missed:float}>}|null
     */
    public function compute(): ?array
    {
        $window = $this->db->fetchOne('SELECT MAX(date_to) FROM yandex_query_stats');
        if ($window === false || $window === null) {
            return null;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT query_text, shows, clicks, position FROM yandex_query_stats WHERE date_to = :d',
            ['d' => $window],
        );

        $ctrTop3       = $this->ctr(3);
        $captured      = 0;
        $potentialTop3 = 0.0;
        $opps          = [];

        foreach ($rows as $r) {
            $shows = (int) $r['shows'];
            $pos   = (float) $r['position'];
            $captured += (int) $r['clicks'];
            $nowEst  = $shows * $this->ctr($pos);
            $top3Est = $shows * $ctrTop3;
            $potentialTop3 += $top3Est;
            $missed = $top3Est - $nowEst;
            if ($missed > 0.5) {
                $opps[] = ['q' => (string) $r['query_text'], 'shows' => $shows, 'pos' => $pos,
                    'now' => round($nowEst, 1), 'top3' => round($top3Est, 1), 'missed' => round($missed, 1)];
            }
        }
        usort($opps, static fn($a, $b) => $b['missed'] <=> $a['missed']);

        $demand = (int) $this->db->fetchOne('SELECT COALESCE(SUM(monthly_shows), 0) FROM brand_keyword');

        return [
            'window'        => (string) $window,
            'queries'       => count($rows),
            'captured'      => $captured,
            'potentialTop3' => (int) round($potentialTop3),
            'missed'        => (int) round($potentialTop3 - $captured),
            'factor'        => $captured > 0 ? round($potentialTop3 / $captured, 1) : 0.0,
            'demand'        => $demand,
            'addressable'   => (int) round($demand * $ctrTop3),
            'opportunities' => $opps,
        ];
    }
}
