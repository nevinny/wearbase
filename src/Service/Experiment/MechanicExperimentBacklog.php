<?php

declare(strict_types=1);

namespace App\Service\Experiment;

/**
 * Статичный бэклог гипотез над МЕХАНИКАМИ сайта (docs/mechanic_experiments.md).
 * Источник — docs/klyucharev_decisions_2026.md «Второй проход» (нейроэкономика → продукт):
 * OFC-паралич от избытка опций (один CTA / 3 подборки вместо веера фильтров), in-group-конформизм
 * («бренды вашего уровня»), гарантии доверия на первый экран, счётчик прироста.
 *
 * Как добавить гипотезу: дописать элемент в ALL с уникальным `code`, метрикой из
 * CohortMetricProbe::METRICS и когортами A/B (kind: brand_parity | brand_ids | page_like).
 * propose подхватит новый code в ближайший понедельник (идемпотентно по code).
 *
 * Когорты для card-механик (правка карточки бренда) — 50/50 holdout по чётности brand.id
 * (владелец гейтит Twig условием `brand.id % 2`). Для hub-механик — DiD «затронутый тип
 * страниц (style) vs незатронутый (city) как сезонный контроль».
 */
final class MechanicExperimentBacklog
{
    /**
     * @return list<array{code:string,hypothesis:string,target:string,metric:string,cohort_a:array,cohort_b:array,impact:int,confidence:int,ease:int,period_days:int}>
     */
    public static function all(): array
    {
        return [
            [
                'code'        => 'single_cta',
                'hypothesis'  => 'Один CTA вместо нескольких на карточке бренда (анти-OFC-паралич: избыток опций → орбитофронтальная кора не присуждает ценность).',
                'target'      => 'templates/tailwind/brand/show.html.twig — блок действий (гейт: brand.id % 2 == 0)',
                'metric'      => 'card_conversion',
                'cohort_a'    => ['kind' => 'brand_parity', 'parity' => 0],
                'cohort_b'    => ['kind' => 'brand_parity', 'parity' => 1],
                'impact'      => 7,
                'confidence'  => 6,
                'ease'        => 8,
                'period_days' => 21,
            ],
            [
                'code'        => 'zero_commission_hero',
                'hypothesis'  => 'Гарантия «0% комиссии, деньги напрямую бренду» на первый экран карточки (наказание за обман доверия дофаминно → гарантия усиливает решение).',
                'target'      => 'templates/tailwind/brand/show.html.twig — hero/первый экран (гейт: brand.id % 2 == 0)',
                'metric'      => 'card_conversion',
                'cohort_a'    => ['kind' => 'brand_parity', 'parity' => 0],
                'cohort_b'    => ['kind' => 'brand_parity', 'parity' => 1],
                'impact'      => 6,
                'confidence'  => 6,
                'ease'        => 7,
                'period_days' => 21,
            ],
            [
                'code'        => 'similar_level_top',
                'hypothesis'  => 'Блок «похожие бренды вашего уровня» выше на карточке (in-group-конформизм: «свои» → доверие и вовлечение).',
                'target'      => 'templates/tailwind/brand/show.html.twig — позиция блока «похожие» (гейт: brand.id % 2 == 0)',
                'metric'      => 'card_conversion',
                'cohort_a'    => ['kind' => 'brand_parity', 'parity' => 0],
                'cohort_b'    => ['kind' => 'brand_parity', 'parity' => 1],
                'impact'      => 6,
                'confidence'  => 5,
                'ease'        => 6,
                'period_days' => 21,
            ],
            [
                'code'        => 'three_collections',
                'hypothesis'  => '3 готовые подборки вместо веера фильтров в хабах (анти-OFC-паралич: перебор опций → оцепенение вместо решения).',
                'target'      => 'шаблоны хабов стиля (/ru/style/*) — замена панели фильтров на 3 подборки',
                'metric'      => 'search_ctr',
                'cohort_a'    => ['kind' => 'page_like', 'like' => '%/style/%'],
                'cohort_b'    => ['kind' => 'page_like', 'like' => '%/city/%'],
                'impact'      => 7,
                'confidence'  => 5,
                'ease'        => 5,
                'period_days' => 21,
            ],
            [
                'code'        => 'new_brands_counter',
                'hypothesis'  => 'Счётчик «+N брендов за неделю» в хабах стиля (сравнение с другими + прогноз награды → вовлечение).',
                'target'      => 'шаблоны хабов стиля (/ru/style/*) — счётчик прироста на первом экране',
                'metric'      => 'search_ctr',
                'cohort_a'    => ['kind' => 'page_like', 'like' => '%/style/%'],
                'cohort_b'    => ['kind' => 'page_like', 'like' => '%/city/%'],
                'impact'      => 5,
                'confidence'  => 5,
                'ease'        => 7,
                'period_days' => 21,
            ],
            [
                'code'        => 'similar_brands_hub',
                'hypothesis'  => 'Блок «бренды вашего уровня уже здесь» в хабах стиля (in-group-конформизм на уровне хаба).',
                'target'      => 'шаблоны хабов стиля (/ru/style/*) — блок рекомендованных брендов',
                'metric'      => 'search_ctr',
                'cohort_a'    => ['kind' => 'page_like', 'like' => '%/style/%'],
                'cohort_b'    => ['kind' => 'page_like', 'like' => '%/city/%'],
                'impact'      => 5,
                'confidence'  => 5,
                'ease'        => 6,
                'period_days' => 21,
            ],
            [
                'code'        => 'trust_guarantee_hub',
                'hypothesis'  => 'Гарантия доверия (деньги напрямую бренду) на первый экран хабов стиля (гарантия усиливает решение).',
                'target'      => 'шаблоны хабов стиля (/ru/style/*) — плашка гарантии на первом экране',
                'metric'      => 'search_ctr',
                'cohort_a'    => ['kind' => 'page_like', 'like' => '%/style/%'],
                'cohort_b'    => ['kind' => 'page_like', 'like' => '%/city/%'],
                'impact'      => 5,
                'confidence'  => 4,
                'ease'        => 6,
                'period_days' => 21,
            ],
        ];
    }
}
