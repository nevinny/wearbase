<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Переключение языка интерфейса.
 *
 * POST /locale/switch  — устанавливает cookie `locale` и редиректит обратно.
 */
class LocaleController extends AbstractController
{
    private const SUPPORTED = ['ru', 'en', 'zh', 'ar', 'tr', 'de', 'fr', 'es', 'ko'];
    private const COOKIE_TTL = 30 * 24 * 3600; // 30 дней

    #[Route('/locale/switch', name: 'locale_switch', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        $locale = $request->request->get('locale', 'ru');

        // Нормализуем и валидируем
        $locale = strtolower(substr((string) $locale, 0, 5));
        if (!in_array($locale, self::SUPPORTED, true)) {
            $locale = 'ru';
        }

        $referer = $request->headers->get('Referer', '/');
        $targetUrl = $this->rewriteLocaleInUrl($referer, $locale);
        $response = $this->redirect($targetUrl);

        $cookie = Cookie::create('locale')
            ->withValue($locale)
            ->withExpires(time() + self::COOKIE_TTL)
            ->withPath('/')
            ->withSameSite('lax')
            ->withSecure(false)
            ->withHttpOnly(false);

        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * Заменяет языковой префикс в URL-пути.
     *
     * Примеры:
     *   /ru/brands/some-brand + en → /en/brands/some-brand
     *   /ru/             + en → /en/
     *   /some/path       + en → /en/some/path   (нет префикса — добавляем)
     *   https://site.ru/ru/brands + en → https://site.ru/en/brands
     */
    private function rewriteLocaleInUrl(string $url, string $newLocale): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach (self::SUPPORTED as $loc) {
            if ($path === '/' . $loc || str_starts_with($path, '/' . $loc . '/')) {
                $newPath = '/' . $newLocale . substr($path, \strlen('/' . $loc));
                if ($newPath === '') {
                    $newPath = '/';
                }
                // Восстанавливаем полный URL, заменяя path
                return $this->rebuildUrl($url, $path, $newPath);
            }
        }

        // Нет известного префикса — просто идём на /{locale}/
        return '/' . $newLocale . '/';
    }

    private function rebuildUrl(string $original, string $oldPath, string $newPath): string
    {
        // Если URL относительный — просто заменяем путь
        $scheme = parse_url($original, PHP_URL_SCHEME);
        if (!$scheme) {
            $query    = parse_url($original, PHP_URL_QUERY);
            $fragment = parse_url($original, PHP_URL_FRAGMENT);
            $result   = $newPath;
            if ($query)    { $result .= '?' . $query; }
            if ($fragment) { $result .= '#' . $fragment; }
            return $result;
        }

        // Абсолютный URL
        $host     = parse_url($original, PHP_URL_HOST) ?? '';
        $port     = parse_url($original, PHP_URL_PORT);
        $query    = parse_url($original, PHP_URL_QUERY);
        $fragment = parse_url($original, PHP_URL_FRAGMENT);

        $result = $scheme . '://' . $host;
        if ($port) { $result .= ':' . $port; }
        $result .= $newPath;
        if ($query)    { $result .= '?' . $query; }
        if ($fragment) { $result .= '#' . $fragment; }
        return $result;
    }
}
