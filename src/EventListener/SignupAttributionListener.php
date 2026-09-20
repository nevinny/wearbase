<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Первое касание (first-touch attribution): при первом GET без куки `wb_src`
 * запоминает источник визита (utm_*, referrer, лендинг) на 180 дней. RegisterController
 * читает куку при регистрации и заполняет signup_* поля User
 * (docs/registration_sources_2026_09.md).
 *
 * Пишем один раз: если кука уже есть — не трогаем (нужно именно ПЕРВОЕ касание).
 * Response-обработчик стоит на priority -10, ПОСЛЕ core ResponseListener — тот выставляет
 * дефолтный Content-Type: text/html в Response::prepare() только там, иначе для обычных
 * Twig-ответов заголовка ещё нет и наш HTML-фильтр молча выключает куку везде.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: -10)]
class SignupAttributionListener
{
    public const COOKIE_NAME = 'wb_src';

    private const COOKIE_TTL = 180 * 24 * 3600; // 180 дней

    private const REQUEST_ATTRIBUTE = '_signup_attribution_payload';

    // Служебные/трекинговые маршруты — не первое касание пользователя на сайте.
    private const SKIP_PREFIXES = [
        '/admin',
        '/_profiler',
        '/cart/count',
        '/go/',
        // outreach-трекинг (аналог /go/): /e/c/ — редирект-прокладка клика по письму,
        // /e/o/ — пиксель открытия (и так non-HTML), /e/u/ — отписка. Все три — не лендинг.
        '/e/',
        '/locale/switch',
        '/verify-email',
    ];

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->isMethod('GET') || $request->cookies->has(self::COOKIE_NAME)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $this->buildPayload($request));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $payload = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (!is_array($payload)) {
            return;
        }

        $response = $event->getResponse();
        if (!str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue((string) json_encode($payload, JSON_UNESCAPED_UNICODE))
            ->withExpires(time() + self::COOKIE_TTL)
            ->withPath('/')
            ->withSameSite('lax')
            ->withSecure(true)
            ->withHttpOnly(true);

        $response->headers->setCookie($cookie);
    }

    /**
     * @return array<string, string|bool|null>
     */
    private function buildPayload(Request $request): array
    {
        $query = $request->query;

        $payload = [
            'utm_source'   => $this->normalizeUtm($query->get('utm_source')),
            'utm_medium'   => $this->normalizeUtm($query->get('utm_medium')),
            'utm_campaign' => $this->normalizeUtm($query->get('utm_campaign')),
            'ref'          => $this->normalizeReferer($request->headers->get('Referer'), $request->getHost()),
            'lp'           => mb_substr($request->getPathInfo(), 0, 255),
            'ts'           => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        // ysclid переживает в query лендинга даже когда браузер обрезает Referer при переходе
        // с Яндекса — единственный момент, когда он виден: классификатор в RegisterController
        // читает уже сохранённую куку, а query первой страницы там недоступен.
        if ($query->get('ysclid') !== null) {
            $payload['ysclid'] = true;
        }

        return $payload;
    }

    private function normalizeUtm(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private function normalizeReferer(?string $referer, string $ownHost): ?string
    {
        if (!$referer) {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!$host || strcasecmp($host, $ownHost) === 0) {
            return null; // свой хост — для атрибуции считаем «реферера нет»
        }

        $path = (string) parse_url($referer, PHP_URL_PATH);

        return mb_substr($host . $path, 0, 255);
    }
}
