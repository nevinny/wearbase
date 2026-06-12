<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Сервис конвертации валют.
 *
 * Загружает актуальные курсы из БД один раз за запрос (кешируется через Symfony Cache).
 * Если курс не найден — возвращает null и логирует предупреждение.
 *
 * Использование:
 *
 *   $usd = $converter->convert(1500.0, 'RUB', 'USD');   // float|null
 *   $fmt = $converter->format(1500.0, 'RUB', 'USD');    // "$16.50" | "1 500 ₽"
 *   $all = $converter->convertToAll(1500.0, 'RUB');      // ['USD'=>16.5, 'EUR'=>15.2, …]
 */
class CurrencyConverter
{
    /** rate map: "BASE→TARGET" => float */
    private array $rateMap = [];
    private bool  $loaded  = false;

    public function __construct(
        private readonly CurrencyRepository      $currencyRepo,
        private readonly ExchangeRateRepository  $rateRepo,
        private readonly CacheInterface          $cache,
        private readonly LoggerInterface         $logger,
    ) {}

    /**
     * Конвертирует сумму из одной валюты в другую.
     *
     * @return float|null  null если курс не найден
     */
    public function convert(float $amount, string $fromCode, string $toCode): ?float
    {
        if ($fromCode === $toCode) {
            return $amount;
        }

        $this->ensureLoaded();
        $key = strtoupper($fromCode) . '→' . strtoupper($toCode);

        if (!isset($this->rateMap[$key])) {
            // Пробуем обратный курс
            $reverseKey = strtoupper($toCode) . '→' . strtoupper($fromCode);
            if (isset($this->rateMap[$reverseKey]) && $this->rateMap[$reverseKey] > 0) {
                return $amount / $this->rateMap[$reverseKey];
            }

            $this->logger->warning('ExchangeRate not found', [
                'from' => $fromCode,
                'to'   => $toCode,
            ]);
            return null;
        }

        return $amount * $this->rateMap[$key];
    }

    /**
     * Конвертирует и форматирует результат с символом целевой валюты.
     * Возвращает null если курс недоступен.
     */
    public function format(float $amount, string $fromCode, string $toCode): ?string
    {
        $converted = $this->convert($amount, $fromCode, $toCode);
        if ($converted === null) {
            return null;
        }

        $currency = $this->currencyRepo->findByCode($toCode);
        if (!$currency) {
            return number_format($converted, 2) . ' ' . strtoupper($toCode);
        }

        return $currency->format($converted);
    }

    /**
     * Конвертирует сумму во все активные валюты.
     *
     * @return array<string, float>  ['USD' => 16.5, 'EUR' => 15.2, …]
     */
    public function convertToAll(float $amount, string $fromCode): array
    {
        $this->ensureLoaded();
        $result = [];

        foreach ($this->rateMap as $pair => $rate) {
            [$base, $target] = explode('→', $pair);
            if ($base === strtoupper($fromCode)) {
                $result[$target] = $amount * $rate;
            }
        }

        return $result;
    }

    /**
     * Сбрасывает кеш курсов (вызывать после обновления курсов).
     */
    public function clearCache(): void
    {
        $this->cache->delete('exchange_rates_map');
        $this->loaded  = false;
        $this->rateMap = [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->rateMap = $this->cache->get('exchange_rates_map', function (ItemInterface $item): array {
            $item->expiresAfter(3600); // 1 час

            $base = $this->currencyRepo->findBase();
            if (!$base) {
                return [];
            }

            $rates = $this->rateRepo->findLatestForBase($base);
            $map   = [];
            foreach ($rates as $rate) {
                $key       = $base->getCode() . '→' . $rate->getTargetCurrency()->getCode();
                $map[$key] = (float) $rate->getRate();
            }

            return $map;
        });

        $this->loaded = true;
    }
}
