<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Учёт обращений к Wordstat API (Yandex Cloud Search API, `v2/wordstat/topRequests`).
 *
 * Зачем: до этого запросы Wordstat нигде не считались — в `api_usage_daily` были только
 * `brave_search` и `wardrobe_ai`, то есть расход на ключевики был для нас невидим, хотя
 * это тарифицируемый запрос (1 запрос = 1 бренд, `app:brand:keywords`).
 *
 * Хранилище и upsert — как у YandexSearchMeter/BraveSearchMeter (та же таблица
 * `api_usage_daily`), счётчик корректен при параллельных воркерах.
 *
 * **Только учёт, без потолка** — сознательно. Дневной кап здесь пробрасывать нечем:
 * `CollectBrandKeywordsCommand` ловит `WordstatQuotaException` как ЧАСОВУЮ квоту и уходит
 * в паузы с повторами, а возврат пустого массива пометил бы бренды как «нет ключевиков».
 * Понадобится жёсткий кап — добавлять вместе с отдельным исключением и его обработкой.
 *
 * Тариф Wordstat документацией не подтверждён (страницы отдают капчу), поэтому ставка —
 * аргумент конструктора со значением 0 по умолчанию: считаем запросы, деньги не оцениваем.
 * Через env специально НЕ пробрасываем — `.env` в этом репозитории не трекается, и
 * `%env(float:…)%` без переменной уронил бы контейнер на проде. Появится реальная цена в
 * биллинге Yandex Cloud — прописать её аргументом в services.yaml. Источник истины по
 * деньгам всё равно консоль биллинга; эта таблица отвечает «сколько запросов мы сделали».
 */
class WordstatMeter
{
    private const SERVICE = 'wordstat';

    public function __construct(
        private readonly Connection $db,
        private readonly float $costPerRequestRub = 0.0,
    ) {
    }

    /** Зафиксировать один тарифицируемый запрос (строка за сегодня). */
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

    /** Сумма запросов за текущий календарный месяц (биллинг у Yandex Cloud месячный). */
    public function monthCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(requests), 0) FROM api_usage_daily
             WHERE service = :s AND usage_date >= :from',
            ['s' => self::SERVICE, 'from' => (new \DateTimeImmutable('first day of this month'))->format('Y-m-d')],
        );
    }

    /** Оценка расхода за месяц в ₽. 0.0 при неустановленной ставке = «тариф не подтверждён». */
    public function estimatedMonthCostRub(): float
    {
        return round($this->monthCount() * $this->costPerRequestRub, 2);
    }

    public function costPerRequestRub(): float
    {
        return $this->costPerRequestRub;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
