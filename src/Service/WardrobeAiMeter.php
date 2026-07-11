<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Учёт обращений AI-ассиста добавления вещей (фото/URL) к внешним LLM и дневной
 * потолок расхода. Клон YandexSearchMeter (та же таблица api_usage_daily,
 * тот же атомарный upsert) — общий счётчик на инсталляцию, поверх него
 * per-user rate-limiter (config/packages/rate_limiter.yaml: wardrobe_ai).
 */
class WardrobeAiMeter
{
    private const SERVICE = 'wardrobe_ai';

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

    /** Зафиксировать один инициированный запрос к LLM. */
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

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
