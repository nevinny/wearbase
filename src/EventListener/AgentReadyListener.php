<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Добавляет HTTP-заголовок Link в HTML-ответы, рекламируя llms.txt как
 * machine-readable альтернативу страницы (Discoverability у AI-агентов).
 *
 * Заголовок отдаётся один раз на главный запрос; для не-HTML ответов
 * (sitemap.xml, картинки, JSON) не имеет смысла и пропускается.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final class AgentReadyListener
{
    public function __construct(private readonly string $siteBaseUrl)
    {
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        // На kernel.response Content-Type у обычной HTML-страницы ещё ПУСТ — Symfony
        // проставляет text/html только в Response::prepare() при отправке. Поэтому
        // пропускаем лишь ЯВНО не-HTML ответы (sitemap.xml, llms.txt, JSON, картинки);
        // пустой CT трактуем как HTML.
        $ct = (string) $response->headers->get('Content-Type');
        if ($ct !== '' && !str_contains($ct, 'text/html')) {
            return;
        }

        $link = sprintf(
            '<%s/llms.txt>; rel="alternate"; type="text/markdown"; title="llms.txt"',
            rtrim($this->siteBaseUrl, '/'),
        );
        // append, не перезаписываем — вдруг Link уже выставлен (preload и т.п.)
        $response->headers->set('Link', $link, false);
    }
}
