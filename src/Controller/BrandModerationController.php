<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Agent\BrandUnpublisher;
use App\Service\BrandActionSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Быстрые модерационные действия по подписанной ссылке из TG-уведомления
 * (кнопка «🚫 Скрыть» под дрип-публикацией). Авторизация — не сессия, а
 * подпись key = HMAC(action:id, APP_SECRET) через BrandActionSigner: кликается
 * прямо из Telegram, подделать нельзя. Замена сломанных callback-кнопок
 * (вебхук Telegram→прод таймаутит).
 *
 * GET (одноклик из мессенджера, действие идемпотентно и обратимо — soft-hide).
 */
class BrandModerationController extends AbstractController
{
    #[Route('/mod/brand-action', name: 'brand_moderation_action', methods: ['GET'])]
    public function action(
        Request $request,
        BrandActionSigner $signer,
        BrandUnpublisher $unpublisher,
    ): Response {
        $action = (string) $request->query->get('action', '');
        $id     = (int) $request->query->get('id', 0);
        $key    = (string) $request->query->get('key', '');

        if ($id <= 0 || !$signer->verify($action, $id, $key)) {
            return $this->page('Ссылка недействительна', 'Неверный ключ или бренд — ссылка подделана либо устарела.', false);
        }

        return match ($action) {
            'unpublish' => $this->doUnpublish($id, $unpublisher),
            default     => $this->page('Неизвестное действие', "Действие «{$action}» не поддерживается.", false),
        };
    }

    private function doUnpublish(int $id, BrandUnpublisher $unpublisher): Response
    {
        $res = $unpublisher->hide($id);

        return $this->page(
            $res['ok'] ? '🚫 Бренд скрыт' : 'Не удалось',
            $res['ok'] ? sprintf('«%s» — %s', $res['title'], $res['message']) : $res['message'],
            $res['ok'],
        );
    }

    /** Минимальная самодостаточная страница-подтверждение (открывается в браузере после клика). */
    private function page(string $title, string $message, bool $ok): Response
    {
        $color = $ok ? '#16a34a' : '#dc2626';
        $html  = sprintf(
            '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>%1$s — WEARBASE</title></head>'
            . '<body style="font-family:system-ui,-apple-system,sans-serif;background:#f9fafb;margin:0;'
            . 'display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px">'
            . '<div style="max-width:420px;background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:32px;text-align:center">'
            . '<h1 style="color:%3$s;font-size:22px;margin:0 0 12px">%1$s</h1>'
            . '<p style="color:#374151;font-size:15px;line-height:1.5;margin:0">%2$s</p>'
            . '</div></body></html>',
            htmlspecialchars($title),
            htmlspecialchars($message),
            $color,
        );

        return new Response($html, $ok ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }
}
