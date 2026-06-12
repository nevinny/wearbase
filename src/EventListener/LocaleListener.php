<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\LanguageRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Определяет язык пользователя и устанавливает локаль запроса.
 *
 * Приоритет выбора языка:
 *   1. Cookie `locale` (пользователь явно выбрал язык)
 *   2. Заголовок Accept-Language (язык браузера)
 *   3. Язык по умолчанию платформы (из БД, флаг isDefault)
 *   4. Жёсткий fallback — 'ru'
 *
 * Язык записывается в Request::setLocale() и в атрибут `_locale`,
 * что позволяет Symfony Translator автоматически использовать правильный
 * файл переводов (translations/messages.{locale}.yaml).
 *
 * При первом заходе (нет cookie) язык сохраняется в cookie через ResponseEvent,
 * чтобы следующие запросы не пересчитывали его заново.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 20)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 0)]
class LocaleListener
{
    private const COOKIE_NAME    = 'locale';
    private const COOKIE_TTL     = 30 * 24 * 3600; // 30 дней
    private const SUPPORTED      = ['ru', 'en', 'zh', 'ar', 'tr', 'de', 'fr', 'es', 'ko'];
    private const DEFAULT_LOCALE = 'ru';

    /** Locale, которую нужно записать в cookie (если она изменилась). */
    private ?string $pendingCookieLocale = null;

    public function __construct(
        private readonly LanguageRepository $languageRepo,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Пропускаем admin-маршруты — там locale не нужен.
        if (str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        // Если локаль явно указана в URL (/ru/, /en/, /de/...),
        // используем её, а не cookie/браузер
        $pathLocale = $this->extractPathLocale($request);
        if ($pathLocale) {
            $this->pendingCookieLocale = $pathLocale;
            $request->setLocale($pathLocale);
            $request->attributes->set('_locale', $pathLocale);
            return;
        }

        $locale = $this->resolveLocale($request);

        $request->setLocale($locale);
        $request->attributes->set('_locale', $locale);
    }

    /**
     * Извлекает локаль из первого сегмента URL-пути.
     * Пример: /ru/brands → ru, /en/ → en, / → null
     */
    private function extractPathLocale(Request $request): ?string
    {
        $path = ltrim($request->getPathInfo(), '/');
        if ($path === '') {
            return null;
        }

        $parts = explode('/', $path);
        $first = strtolower($parts[0]);

        if ($this->isSupported($first)) {
            return $first;
        }

        return null;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->pendingCookieLocale === null) {
            return;
        }

        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue($this->pendingCookieLocale)
            ->withExpires(time() + self::COOKIE_TTL)
            ->withPath('/')
            ->withSameSite('lax')
            ->withSecure(false)
            ->withHttpOnly(false); // JS может читать для отображения

        $event->getResponse()->headers->setCookie($cookie);
        $this->pendingCookieLocale = null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveLocale(Request $request): string
    {
        // 1. Cookie
        $cookieLocale = $request->cookies->get(self::COOKIE_NAME);
        if ($cookieLocale && $this->isSupported($cookieLocale)) {
            return $cookieLocale;
        }

        // 2. Accept-Language header
        $acceptLocale = $this->parseAcceptLanguage($request);
        if ($acceptLocale) {
            $this->pendingCookieLocale = $acceptLocale; // сохраним в cookie
            return $acceptLocale;
        }

        // 3. Default из БД
        try {
            $defaultLang = $this->languageRepo->findDefault();
            if ($defaultLang) {
                $this->pendingCookieLocale = $defaultLang->getCode();
                return $defaultLang->getCode();
            }
        } catch (\Throwable) {
            // Таблица language ещё не создана (миграции не запущены)
        }

        // 4. Жёсткий fallback
        $this->pendingCookieLocale = self::DEFAULT_LOCALE;
        return self::DEFAULT_LOCALE;
    }

    private function parseAcceptLanguage(Request $request): ?string
    {
        $header = $request->headers->get('Accept-Language', '');
        if (!$header) {
            return null;
        }

        // Парсим "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7"
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        if ($preferred && $this->isSupported($preferred)) {
            return $preferred;
        }

        return null;
    }

    private function isSupported(string $locale): bool
    {
        // Нормализуем: 'en-US' → 'en', 'zh-Hans' → 'zh'
        $short = strtolower(substr($locale, 0, 2));
        return in_array($short, self::SUPPORTED, true);
    }
}
