<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use App\Entity\StateSnapshot;

/**
 * Детерминированный слой обнаружения аномалий (docs/advisor.md → «Мозг», уровень 1).
 * БЕЗ LLM: компактный набор правил поверх metrics+delta последнего снимка. Половина пользы
 * «опердира» — бесплатно и мгновенно. Пороги — читаемые константы. Направленность важна:
 * падение «хороших» метрик и рост «плохих» накопителей = плохо; рост публикаций = хорошо
 * (не флагаем). Отсутствие ключа / деление на ноль — безопасный пропуск правила, не падаем.
 *
 * @phpstan-type Flag array{key:string, message:string, severity:string, value:int|float|null, delta:int|float|null}
 */
final class AnomalyDetector
{
    public const SEV_HIGH   = 'high';
    public const SEV_MEDIUM = 'medium';
    public const SEV_LOW    = 'low';

    /** Порог относительного скачка н/н (20%). */
    private const REL_JUMP = 0.20;

    /** Минимальный CTR Яндекса (доля). Ниже — сигнал. */
    private const CTR_MIN = 0.01;

    /** Очередь дрипа во сколько раз больше опубликованного = раздутый бэклог. */
    private const DRIP_RATIO_MAX = 5.0;

    /** Доля брендов без контактов от обработанных, выше которой — сигнал. */
    private const NOT_FOUND_SHARE_MAX = 0.30;

    /**
     * «Хорошие» метрики: чем больше — тем лучше. Падение >REL_JUMP н/н → high.
     * @var array<string,string> key => подпись
     */
    private const GOOD_METRICS = [
        'yandex_clicks'        => 'клики Яндекс',
        'yandex_shows'         => 'показы Яндекс',
        'yandex_in_search'     => 'в поиске Яндекс',
        'gsc_indexed'          => 'в индексе Google',
        'brands_published'     => 'опубликовано брендов',
        'prod_published_total' => 'опубликовано (прод)',
        'leads_landing'        => 'лиды с лендинга',
        'leads_newsletter'     => 'подписчики рассылки',
        'subscriptions_active' => 'подписок',
        'subscriptions_trial'  => 'подписок (trial)',
    ];

    /**
     * «Плохие» накопители: чем больше — тем хуже. Рост >REL_JUMP н/н → medium.
     * @var array<string,string> key => подпись
     */
    private const BAD_METRICS = [
        'prod_queue_pending' => 'очередь дрипа',
        'pipeline_review'    => 'pipeline review',
        'pipeline_deferred'  => 'pipeline deferred',
        'contacts_not_found' => 'брендов без контактов',
    ];

    /**
     * @return list<Flag>
     */
    public function detect(StateSnapshot $latest): array
    {
        $m = $latest->getMetrics();
        $d = $latest->getDelta() ?? [];

        $flags = [];

        // --- 1. Относительные скачки по дельте ---
        foreach (self::GOOD_METRICS as $key => $label) {
            $f = $this->relativeDrop($key, $label, $m, $d);
            if ($f !== null) {
                $flags[] = $f;
            }
        }
        foreach (self::BAD_METRICS as $key => $label) {
            $f = $this->relativeRise($key, $label, $m, $d);
            if ($f !== null) {
                $flags[] = $f;
            }
        }

        // --- 2. Пороговые правила по конкретным KPI ---
        $this->pushIf($flags, $this->yandexCtrLow($m));
        $this->pushIf($flags, $this->dripBacklog($m));
        $this->pushIf($flags, $this->pipelineGenerateFailed($m));
        $this->pushIf($flags, $this->contactsNotFoundShare($m));
        $this->pushIf($flags, $this->dripStalledToday($m));

        return $flags;
    }

    /**
     * Падение «хорошей» метрики >REL_JUMP н/н → high.
     * @param array<string,int|float> $m
     * @param array<string,int|float> $d
     * @return Flag|null
     */
    private function relativeDrop(string $key, string $label, array $m, array $d): ?array
    {
        if (!isset($m[$key], $d[$key])) {
            return null;
        }
        $delta = (float) $d[$key];
        if ($delta >= 0) {
            return null; // рост «хорошей» метрики — не флагаем
        }
        $prev = (float) $m[$key] - $delta;
        if ($prev <= 0) {
            return null; // делить не на что
        }
        $pct = -$delta / $prev; // положительная доля падения
        if ($pct < self::REL_JUMP) {
            return null;
        }

        return [
            'key'      => 'drop_' . $key,
            'message'  => sprintf(
                '%s −%d%% н/н (%d → %d)',
                $label,
                (int) round($pct * 100),
                (int) $prev,
                (int) $m[$key],
            ),
            'severity' => self::SEV_HIGH,
            'value'    => $m[$key],
            'delta'    => $d[$key],
        ];
    }

    /**
     * Рост «плохого» накопителя >REL_JUMP н/н → medium.
     * @param array<string,int|float> $m
     * @param array<string,int|float> $d
     * @return Flag|null
     */
    private function relativeRise(string $key, string $label, array $m, array $d): ?array
    {
        if (!isset($m[$key], $d[$key])) {
            return null;
        }
        $delta = (float) $d[$key];
        if ($delta <= 0) {
            return null; // снижение «плохого» накопителя — хорошо, не флагаем
        }
        $prev = (float) $m[$key] - $delta;
        if ($prev <= 0) {
            return null;
        }
        $pct = $delta / $prev;
        if ($pct < self::REL_JUMP) {
            return null;
        }

        return [
            'key'      => 'rise_' . $key,
            'message'  => sprintf(
                '%s +%d%% н/н (%d → %d)',
                $label,
                (int) round($pct * 100),
                (int) $prev,
                (int) $m[$key],
            ),
            'severity' => self::SEV_MEDIUM,
            'value'    => $m[$key],
            'delta'    => $d[$key],
        ];
    }

    /**
     * CTR Яндекса ниже CTR_MIN → medium.
     * @param array<string,int|float> $m
     * @return Flag|null
     */
    private function yandexCtrLow(array $m): ?array
    {
        $shows  = (float) ($m['yandex_shows'] ?? 0);
        $clicks = (float) ($m['yandex_clicks'] ?? 0);
        if ($shows <= 0) {
            return null;
        }
        $ctr = $clicks / $shows;
        if ($ctr >= self::CTR_MIN) {
            return null;
        }

        return [
            'key'      => 'yandex_ctr_low',
            'message'  => sprintf(
                'низкий CTR Яндекса %.2f%% (%d кликов / %d показов, порог %d%%)',
                $ctr * 100,
                (int) $clicks,
                (int) $shows,
                (int) round(self::CTR_MIN * 100),
            ),
            'severity' => self::SEV_MEDIUM,
            'value'    => round($ctr, 4),
            'delta'    => null,
        ];
    }

    /**
     * Очередь дрипа сильно больше опубликованного (ratio > DRIP_RATIO_MAX) → medium.
     * @param array<string,int|float> $m
     * @return Flag|null
     */
    private function dripBacklog(array $m): ?array
    {
        if (!isset($m['prod_queue_pending'], $m['prod_published_total'])) {
            return null;
        }
        $published = (float) $m['prod_published_total'];
        $queue     = (float) $m['prod_queue_pending'];
        if ($published <= 0) {
            return null;
        }
        $ratio = $queue / $published;
        if ($ratio <= self::DRIP_RATIO_MAX) {
            return null;
        }

        return [
            'key'      => 'drip_backlog',
            'message'  => sprintf(
                'бэклог контента раздут: очередь дрипа %d при %d опубликованных (×%.1f)',
                (int) $queue,
                (int) $published,
                $ratio,
            ),
            'severity' => self::SEV_MEDIUM,
            'value'    => round($ratio, 1),
            'delta'    => null,
        ];
    }

    /**
     * Любые сбои генерации контента → high.
     * @param array<string,int|float> $m
     * @return Flag|null
     */
    private function pipelineGenerateFailed(array $m): ?array
    {
        $failed = (int) ($m['pipeline_generate_failed'] ?? 0);
        if ($failed <= 0) {
            return null;
        }

        return [
            'key'      => 'pipeline_generate_failed',
            'message'  => sprintf('сбои генерации контента: %d в pipeline (generate_failed)', $failed),
            'severity' => self::SEV_HIGH,
            'value'    => $failed,
            'delta'    => null,
        ];
    }

    /**
     * Большая доля брендов без контактов от обработанных → low.
     * Знаменатель — весь охват контакт-воронки (enriched+partial+not_found),
     * а не brands_active: воронка идёт по всему каталогу, share от active был бы >100%.
     * @param array<string,int|float> $m
     * @return Flag|null
     */
    private function contactsNotFoundShare(array $m): ?array
    {
        $notFound  = (float) ($m['contacts_not_found'] ?? 0);
        $processed = $notFound
            + (float) ($m['contacts_enriched'] ?? 0)
            + (float) ($m['contacts_partial'] ?? 0);
        if ($processed <= 0) {
            return null;
        }
        $share = $notFound / $processed;
        if ($share <= self::NOT_FOUND_SHARE_MAX) {
            return null;
        }

        return [
            'key'      => 'contacts_not_found_share',
            'message'  => sprintf(
                'нет контактов у %d брендов — %d%% обработанных (порог %d%%)',
                (int) $notFound,
                (int) round($share * 100),
                (int) round(self::NOT_FOUND_SHARE_MAX * 100),
            ),
            'severity' => self::SEV_LOW,
            'value'    => round($share, 3),
            'delta'    => null,
        ];
    }

    /**
     * Дрип встал сегодня: 0 публикаций при непустой очереди → medium.
     * @param array<string,int|float> $m
     * @return Flag|null
     */
    private function dripStalledToday(array $m): ?array
    {
        if (!isset($m['prod_published_today'], $m['prod_queue_pending'])) {
            return null;
        }
        $today = (int) $m['prod_published_today'];
        $queue = (int) $m['prod_queue_pending'];
        if ($today !== 0 || $queue <= 0) {
            return null;
        }

        return [
            'key'      => 'drip_stalled_today',
            'message'  => sprintf('дрип встал сегодня: 0 публикаций при очереди %d', $queue),
            'severity' => self::SEV_MEDIUM,
            'value'    => 0,
            'delta'    => null,
        ];
    }

    /**
     * @param list<Flag> $flags
     * @param Flag|null  $flag
     */
    private function pushIf(array &$flags, ?array $flag): void
    {
        if ($flag !== null) {
            $flags[] = $flag;
        }
    }
}
