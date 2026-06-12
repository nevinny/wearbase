<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Управляет выбором валюты пользователем.
 *
 * Валюта хранится в cookie `currency` (30 дней).
 * Если cookie нет — используется базовая валюта платформы (RUB).
 *
 * Использование в контроллере:
 *
 *   $currency = $currencySession->getCurrent();   // Currency
 *   $response = new Response(...);
 *   $currencySession->setCurrency('USD', $response);  // пишет cookie в response
 */
class CurrencySession
{
    private const COOKIE_NAME = 'currency';
    private const COOKIE_TTL  = 30 * 24 * 3600; // 30 дней

    private ?Currency $current = null;

    public function __construct(
        private readonly CurrencyRepository $currencyRepo,
        private readonly RequestStack       $requestStack,
    ) {}

    /**
     * Возвращает текущую валюту пользователя.
     * Читает cookie → ищет в БД → fallback на базовую (RUB).
     */
    public function getCurrent(): ?Currency
    {
        if ($this->current !== null) {
            return $this->current;
        }

        try {
            $request = $this->requestStack->getCurrentRequest();
            $code    = $request?->cookies->get(self::COOKIE_NAME);

            if ($code) {
                $currency = $this->currencyRepo->findByCode($code);
                if ($currency && $currency->isActive()) {
                    $this->current = $currency;
                    return $this->current;
                }
            }

            // Fallback: базовая валюта
            $this->current = $this->currencyRepo->findBase()
                ?? $this->currencyRepo->findOneBy(['isActive' => true]);
        } catch (\Throwable) {
            // Таблица currency не существует (миграции не запущены) — возвращаем null.
            // CurrencyExtension::getGlobals() и formatPrice() обрабатывают null безопасно.
            return null;
        }

        return $this->current;
    }

    /**
     * Устанавливает валюту через cookie в Response.
     * Вызывается из CurrencyController после выбора пользователем.
     */
    public function setCurrency(string $code, Response $response): void
    {
        $currency = $this->currencyRepo->findByCode(strtoupper($code));
        if (!$currency || !$currency->isActive()) {
            return;
        }

        $this->current = $currency;

        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue($code)
            ->withExpires(time() + self::COOKIE_TTL)
            ->withPath('/')
            ->withSameSite('lax')
            ->withSecure(false) // true на prod с HTTPS
            ->withHttpOnly(false); // JS читает для отображения

        $response->headers->setCookie($cookie);
    }

    /**
     * Возвращает код текущей валюты (например 'RUB').
     */
    public function getCurrentCode(): string
    {
        return $this->getCurrent()?->getCode() ?? 'RUB';
    }
}
