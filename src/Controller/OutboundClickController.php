<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Трекинг исходящих переходов на ссылки бренда (сайт/соцсети/маркетплейсы).
 *
 * Зачем: в эпоху AI-выдачи (zero-click) единственная защищаемая ценность каталога —
 * ДОКАЗУЕМЫЙ реферальный клик к бренду. Прокладка /go/{id} измеряет его и копит
 * brand.outbound_click_count (популярность) + append-only лог brand_outbound_click.
 *
 * Stateless, PUBLIC_ACCESS (^/go в security.yaml), без CSRF — как outreach-трекинг.
 * Open-redirect НЕВОЗМОЖЕН: цель берётся из БД по id ссылки, а не из query.
 * Горячий путь — нативный SQL. Боты редиректятся, но НЕ учитываются.
 */
class OutboundClickController extends AbstractController
{
    private const BOT_UA = '~(GoogleImageProxy|YahooMailProxy|bot|crawler|spider|preview|monitor|HeadlessChrome|python-requests|curl)~i';

    #[Route('/go/{id}', name: 'brand_outbound_click', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function go(int $id, Request $request, Connection $db, RateLimiterFactory $outboundClickLimiter): Response
    {
        if (!$outboundClickLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('Too many requests', 429);
        }

        // Цель и бренд — из БД (open-redirect невозможен). Только активный бренд: скрытые/удалённые не редиректим.
        $row = $db->fetchAssociative(
            "SELECT bl.link_url, bl.brand_id, b.status
               FROM brand_link bl
               JOIN brand b ON b.id = bl.brand_id
              WHERE bl.id = :id",
            ['id' => $id],
        );

        if ($row === false || empty($row['link_url']) || $row['status'] !== 'active') {
            return new RedirectResponse('https://wearbase.ru/ru/');
        }

        $url = (string) $row['link_url'];

        // Боты (превью/сканеры) переходят, но не искажают статистику.
        if (preg_match(self::BOT_UA, (string) $request->headers->get('User-Agent', '')) !== 1) {
            $ua = (string) $request->headers->get('User-Agent', '');
            $ref = $request->headers->get('Referer');

            $db->executeStatement(
                'INSERT INTO brand_outbound_click
                    (brand_id, brand_link_id, link_type, target_host, locale, referer, ua_hash, created_at)
                 VALUES (:brand_id, :link_id, :type, :host, :locale, :referer, :ua, NOW())',
                [
                    'brand_id' => (int) $row['brand_id'],
                    'link_id'  => $id,
                    'type'     => $this->classify($url),
                    'host'     => mb_substr((string) parse_url($url, PHP_URL_HOST), 0, 255) ?: null,
                    'locale'   => $request->getLocale(),
                    'referer'  => $ref !== null ? mb_substr($ref, 0, 255) : null,
                    'ua'       => $ua !== '' ? hash('sha256', $ua) : null,
                ],
            );
            $db->executeStatement(
                'UPDATE brand SET outbound_click_count = outbound_click_count + 1 WHERE id = :id',
                ['id' => (int) $row['brand_id']],
            );
        }

        $resp = new RedirectResponse($url, 302);
        $resp->headers->set('X-Robots-Tag', 'noindex');         // прокладка не должна индексироваться
        $resp->headers->set('Referrer-Policy', 'no-referrer');  // не утекать наш URL бренду как referer

        return $resp;
    }

    /** Нормализованный тип цели по хосту (link_type из enrichment часто 'other'). */
    private function classify(string $url): string
    {
        $h = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($h, 'instagram.com')                          => 'instagram',
            str_contains($h, 'vk.com') || str_contains($h, 'vkontakte') => 'vk',
            str_contains($h, 't.me') || str_contains($h, 'telegram.')   => 'telegram',
            str_contains($h, 'youtube.com') || str_contains($h, 'youtu.be') => 'youtube',
            str_contains($h, 'tiktok.com')                             => 'tiktok',
            str_contains($h, 'wildberries.') || str_contains($h, 'ozon.')
                || str_contains($h, 'lamoda.') || str_contains($h, 'market.yandex.') => 'marketplace',
            $h === ''                                                  => 'other',
            default                                                    => 'website',
        };
    }
}
