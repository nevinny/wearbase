<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\HtmlToMarkdownConverter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Markdown content negotiation: при `Accept: text/markdown` отдаём markdown-версию
 * HTML-страницы (HTML остаётся дефолтом для браузеров). См. docs/agent_readiness.md
 * и https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/.
 *
 * Priority 10 — выше AgentReadyListener (0): к моменту его работы Content-Type уже
 * text/markdown, поэтому Link-заголовок на markdown-ответ не вешается.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: 10)]
final class MarkdownNegotiationListener
{
    /** Лимит Cloudflare для edge-конвертации; держим тот же порог. */
    private const MAX_BYTES = 2_097_152;

    public function __construct(private readonly HtmlToMarkdownConverter $converter)
    {
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_contains(strtolower($request->headers->get('Accept', '')), 'text/markdown')) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getStatusCode() !== 200) {
            return;
        }

        // Конвертируем только HTML. CT у обычной страницы на kernel.response ещё пуст
        // (Symfony ставит его в prepare()), поэтому пустой CT трактуем как HTML.
        $ct = (string) $response->headers->get('Content-Type');
        if ($ct !== '' && !str_contains($ct, 'text/html')) {
            return;
        }

        $html = (string) $response->getContent();
        if ($html === '' || strlen($html) > self::MAX_BYTES) {
            return;
        }

        $markdown = $this->converter->convert($html, $request->getUri());

        $response->setContent($markdown);
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set('Vary', 'Accept');
        $response->headers->set('X-Markdown-Tokens', (string) (int) ceil(mb_strlen($markdown) / 4));
        $response->headers->set('X-Original-Tokens', (string) (int) ceil(strlen($html) / 4));
    }
}
