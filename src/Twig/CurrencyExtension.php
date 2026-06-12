<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use App\Service\CurrencyConverter;
use App\Service\CurrencySession;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-расширение для работы с валютами.
 *
 * Доступные функции и фильтры в шаблонах:
 *
 *   {{ price(1500) }}               → "1 500 ₽" (текущая валюта пользователя)
 *   {{ price(1500, 'USD') }}        → "$16.25"
 *   {{ 1500|price }}                → "1 500 ₽"
 *   {{ current_currency() }}        → Currency объект
 *   {{ current_currency_code() }}   → "RUB"
 *
 * Глобальные переменные (доступны в любом шаблоне):
 *   {{ app_currency.code }}         → "RUB"
 *   {{ app_currency.symbol }}       → "₽"
 *   {{ currencies_list }}           → Currency[]
 *
 * ВАЖНО: getGlobals() намеренно обёрнут в try-catch.
 * Symfony инициализирует Twig-расширения при старте контейнера,
 * в том числе во время CLI-команд (например doctrine:migrations:migrate).
 * Если таблица currency ещё не создана — возвращаем безопасные заглушки,
 * чтобы не блокировать выполнение миграций.
 */
class CurrencyExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly CurrencyConverter  $converter,
        private readonly CurrencySession    $session,
        private readonly CurrencyRepository $currencyRepo,
    ) {}

    public function getGlobals(): array
    {
        try {
            return [
                'app_currency'    => $this->session->getCurrent(),
                'currencies_list' => $this->currencyRepo->findActive(),
            ];
        } catch (\Throwable) {
            // Таблица currency ещё не существует (миграция не запущена).
            // Возвращаем заглушки — шаблоны получат null/[] и не упадут.
            return [
                'app_currency'    => null,
                'currencies_list' => [],
            ];
        }
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('price', $this->formatPrice(...), ['is_safe' => ['html']]),
            new TwigFunction('current_currency', fn() => $this->session->getCurrent()),
            new TwigFunction('current_currency_code', $this->session->getCurrentCode(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('price', $this->formatPrice(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Конвертирует сумму из базовой валюты (RUB) в текущую или указанную и форматирует.
     *
     * @param float|int|string $amount   Сумма в базовой валюте
     * @param string|null      $toCode   Код целевой валюты (null = текущая из cookie)
     * @param string           $fromCode Код исходной валюты (по умолчанию RUB)
     */
    public function formatPrice(
        float|int|string $amount,
        ?string $toCode = null,
        string $fromCode = 'RUB'
    ): string {
        $amount = (float) $amount;

        try {
            $target    = $toCode ?? $this->session->getCurrentCode();
            $formatted = $this->converter->format($amount, $fromCode, $target);

            // Курс не найден — показываем в исходной валюте
            if ($formatted === null) {
                $formatted = $this->converter->format($amount, $fromCode, $fromCode);
            }

            return $formatted ?? number_format($amount, 0, '.', ' ') . ' ₽';
        } catch (\Throwable) {
            // Таблица currency не существует (идут миграции) — простой fallback
            return number_format($amount, 0, '.', ' ') . ' ₽';
        }
    }
}
