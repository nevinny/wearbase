<?php

namespace App\Controller\Outreach;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Трекинг email-активации: пиксель открытия / клик-редирект / отписка (RFC 8058).
 * Stateless, PUBLIC_ACCESS (^/e в security.yaml), без CSRF — как вебхуки.
 * Горячие пути — нативный SQL. KPI воронки — КЛИК (opens завышены сканерами).
 */
class OutreachController extends AbstractController
{
    private const OPEN_GRACE_SECONDS = 5; // хиты в первые секунды после отправки = сканер
    private const BOT_UA = '~(GoogleImageProxy|YahooMailProxy|bot|crawler|spider|preview|monitor|HeadlessChrome|python-requests|curl)~i';

    /** 1×1 прозрачный GIF. ВСЕГДА 200 (битый токен/бот — просто без учёта): иначе сломанная картинка в письме. */
    #[Route('/e/o/{token}.gif', name: 'outreach_open', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function open(string $token, Request $request, Connection $db, RateLimiterFactory $outreachTrackLimiter): Response
    {
        $allowed = $outreachTrackLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();
        $isBot = preg_match(self::BOT_UA, (string) $request->headers->get('User-Agent', '')) === 1;

        if ($allowed && !$isBot) {
            $db->executeStatement(
                'UPDATE brand_outreach
                    SET open_count = open_count + 1,
                        first_opened_at = COALESCE(first_opened_at, NOW())
                  WHERE send_token = :t
                    AND sent_at IS NOT NULL
                    AND NOW() > sent_at + INTERVAL :grace SECOND',
                ['t' => $token, 'grace' => self::OPEN_GRACE_SECONDS],
            );
        }

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return new Response($gif, 200, [
            'Content-Type'   => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /** Клик: цель строится СЕРВЕРОМ из slug (open-redirect невозможен) + UTM. */
    #[Route('/e/c/{token}', name: 'outreach_click', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function click(string $token, Request $request, Connection $db, RateLimiterFactory $outreachTrackLimiter): Response
    {
        if (!$outreachTrackLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('Too many requests', 429);
        }

        $row = $db->fetchAssociative(
            'SELECT o.id, b.slug FROM brand_outreach o JOIN brand b ON b.id = o.brand_id WHERE o.send_token = :t',
            ['t' => $token],
        );
        if ($row === false) {
            return new RedirectResponse('https://wearbase.ru/ru/');
        }

        if (preg_match(self::BOT_UA, (string) $request->headers->get('User-Agent', '')) !== 1) {
            $db->executeStatement(
                'UPDATE brand_outreach
                    SET click_count = click_count + 1,
                        first_clicked_at = COALESCE(first_clicked_at, NOW())
                  WHERE id = :id',
                ['id' => $row['id']],
            );
        }

        $url = 'https://wearbase.ru/ru/brands/' . rawurlencode((string) $row['slug'])
            . '?utm_source=outreach&utm_medium=email&utm_campaign=brand_invite';

        return new RedirectResponse($url, 302);
    }

    /** Отписка: GET — подтверждение, POST — one-click (RFC 8058). Suppression ПО EMAIL (все бренды владельца). */
    #[Route('/e/u/{token}', name: 'outreach_unsub', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function unsubscribe(string $token, Request $request, Connection $db, RateLimiterFactory $outreachTrackLimiter): Response
    {
        if (!$outreachTrackLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('Too many requests', 429);
        }

        $email = $db->fetchOne('SELECT email FROM brand_outreach WHERE send_token = :t', ['t' => $token]);
        if ($email === false) {
            return new Response('<h2>Ссылка недействительна</h2>', 410, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        if ($request->isMethod('POST')) {
            $db->executeStatement(
                'UPDATE brand_outreach SET unsubscribed_at = COALESCE(unsubscribed_at, NOW()) WHERE email = :e',
                ['e' => $email],
            );

            return new Response(
                '<html lang="ru"><body style="font-family:sans-serif;text-align:center;padding:60px 20px">'
                . '<h2>Вы отписаны</h2><p>Писем от WEARBASE больше не будет.</p></body></html>',
                200, ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        return new Response(
            '<html lang="ru"><body style="font-family:sans-serif;text-align:center;padding:60px 20px">'
            . '<h2>Отписаться от писем WEARBASE?</h2>'
            . '<form method="post"><button type="submit" style="padding:12px 32px;font-size:15px;cursor:pointer">Отписаться</button></form>'
            . '</body></html>',
            200, ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
