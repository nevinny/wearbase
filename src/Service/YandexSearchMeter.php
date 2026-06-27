<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Учёт обращений к Yandex Search API и дневной потолок расхода.
 *
 * Единственная ответственность: считать инициированные (= тарифицируемые) запросы
 * за календарный день и решать, можно ли слать ещё. HTTP-клиент про учёт не знает.
 *
 * Хранилище — таблица api_usage_daily через атомарный upsert: счётчик остаётся
 * корректным при параллельных discover-воркерах (счётчик в кэше дал бы недоучёт).
 */
class YandexSearchMeter
{
    private const SERVICE = 'yandex_search';

    /** Оценочный дневной тариф синхронного web-поиска, ₽/запрос (для прикидки в дашборде). */
    private const COST_PER_REQUEST_RUB = 0.49;

    public function __construct(
        private readonly Connection $db,
        private readonly int $dailyCap,
    ) {
    }

    /** Можно ли инициировать ещё один запрос. Кап ≤ 0 — без ограничения. */
    public function allowed(): bool
    {
        return $this->dailyCap <= 0 || $this->todayCount() < $this->dailyCap;
    }

    /** Зафиксировать один инициированный (тарифицируемый) запрос. */
    public function record(): void
    {
        $this->db->executeStatement(
            'INSERT INTO api_usage_daily (service, usage_date, requests) VALUES (:s, :d, 1)
             ON DUPLICATE KEY UPDATE requests = requests + 1',
            ['s' => self::SERVICE, 'd' => $this->today()],
        );
    }

    public function todayCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT requests FROM api_usage_daily WHERE service = :s AND usage_date = :d',
            ['s' => self::SERVICE, 'd' => $this->today()],
        );
    }

    public function dailyCap(): int
    {
        return $this->dailyCap;
    }

    /** Грубая оценка расхода за сегодня в ₽ (дневной тариф; ночь дешевле — не учитываем). */
    public function estimatedCostRub(): float
    {
        return round($this->todayCount() * self::COST_PER_REQUEST_RUB, 2);
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
