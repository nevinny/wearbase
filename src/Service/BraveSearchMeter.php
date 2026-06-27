<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Учёт обращений к Brave Search API и МЕСЯЧНЫЙ потолок (free-tier 1000 запросов/мес,
 * сбрасывается каждый календарный месяц). Зеркало YandexSearchMeter, но окно — месяц.
 *
 * Хранилище — та же таблица api_usage_daily (per-day строки), сумма за текущий месяц.
 * Атомарный upsert → корректно при параллельных discover-воркерах.
 */
class BraveSearchMeter
{
    private const SERVICE = 'brave_search';

    public function __construct(
        private readonly Connection $db,
        private readonly int $monthlyCap,
    ) {
    }

    /** Можно ли инициировать ещё один запрос в этом месяце. Кап ≤ 0 — без ограничения. */
    public function allowed(): bool
    {
        return $this->monthlyCap <= 0 || $this->monthCount() < $this->monthlyCap;
    }

    /** Зафиксировать один тарифицируемый запрос (строка за сегодня). */
    public function record(): void
    {
        $this->db->executeStatement(
            'INSERT INTO api_usage_daily (service, usage_date, requests) VALUES (:s, :d, 1)
             ON DUPLICATE KEY UPDATE requests = requests + 1',
            ['s' => self::SERVICE, 'd' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
        );
    }

    /** Сумма запросов за текущий календарный месяц. */
    public function monthCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(requests), 0) FROM api_usage_daily
             WHERE service = :s AND usage_date >= :from',
            ['s' => self::SERVICE, 'from' => (new \DateTimeImmutable('first day of this month'))->format('Y-m-d')],
        );
    }

    public function monthlyCap(): int
    {
        return $this->monthlyCap;
    }
}
