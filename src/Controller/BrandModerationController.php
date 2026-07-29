<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Service\Agent\BrandUnpublisher;
use App\Service\BrandActionSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Быстрые модерационные действия по подписанной ссылке из TG-уведомления
 * (кнопка «🚫 Скрыть» под дрип-публикацией, либо approve/request-changes/reject
 * под досье премодерации самрег-бренда). Авторизация — не сессия, а подпись
 * key = HMAC(action:id[:exp], APP_SECRET) через BrandActionSigner: кликается
 * прямо из Telegram, подделать нельзя. Замена сломанных callback-кнопок
 * (вебхук Telegram→прод таймаутит).
 *
 * GET (одноклик из мессенджера, действия идемпотентны).
 */
class BrandModerationController extends AbstractController
{
    #[Route('/mod/brand-action', name: 'brand_moderation_action', methods: ['GET'])]
    public function action(
        Request $request,
        BrandActionSigner $signer,
        BrandUnpublisher $unpublisher,
        EntityManagerInterface $em,
    ): Response {
        $action = (string) $request->query->get('action', '');
        $id     = (int) $request->query->get('id', 0);
        $key    = (string) $request->query->get('key', '');
        $exp    = $request->query->has('exp') ? (int) $request->query->get('exp') : null;

        if ($id <= 0 || !$signer->verify($action, $id, $key, $exp)) {
            return $this->page('Ссылка недействительна', 'Неверный ключ, бренд или истёкшая ссылка.', false);
        }

        return match ($action) {
            'unpublish'       => $this->doUnpublish($id, $unpublisher),
            'approve'         => $this->doApprove($id, $em),
            'request-changes' => $this->doRequestChanges($id, $em),
            'reject'          => $this->doReject($id, $em),
            default           => $this->page('Неизвестное действие', "Действие «{$action}» не поддерживается.", false),
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

    /**
     * Одобрить премодерацию: в очередь дрип-публикации (publish_pending=1 — сам бренд
     * опубликует publish-tick по расписанию). origin_status='unknown' навсегда блокирует
     * publish-tick (см. PublishTickCommand) — при approve считаем сомнение снятым.
     */
    private function doApprove(int $id, EntityManagerInterface $em): Response
    {
        $brand = $em->find(Brand::class, $id);
        if ($brand === null) {
            return $this->page("#{$id}", "Бренд #{$id} не найден.", false);
        }

        $brand->setPublishPending(true);
        if ($brand->getOriginStatus() === 'unknown') {
            $brand->markOrigin('ru', 'auto: одобрено премодерацией самрега', new \DateTime());
        }
        $this->decide($em, $brand, BrandModeration::STATUS_APPROVED, 'publish');

        return $this->page('✅ Одобрено', sprintf('«%s» поставлен в очередь публикации.', $brand->getTitle()), true);
    }

    /** Вернуть владельцу на доработку: статус заявки меняется, карточку не трогаем. */
    private function doRequestChanges(int $id, EntityManagerInterface $em): Response
    {
        $brand = $em->find(Brand::class, $id);
        if ($brand === null) {
            return $this->page("#{$id}", "Бренд #{$id} не найден.", false);
        }

        $this->decide($em, $brand, BrandModeration::STATUS_CHANGES_REQUESTED, 'request_changes');

        return $this->page('✏️ Запрошены правки', sprintf('«%s» — заявка возвращена на доработку.', $brand->getTitle()), true);
    }

    /** Отклонить: soft-hide (disabled — политика проекта, без физического DELETE) + заметка. */
    private function doReject(int $id, EntityManagerInterface $em): Response
    {
        $brand = $em->find(Brand::class, $id);
        if ($brand === null) {
            return $this->page("#{$id}", "Бренд #{$id} не найден.", false);
        }

        $brand->unpublish(); // идемпотентно: new|active|disabled → disabled
        $this->decide($em, $brand, BrandModeration::STATUS_REJECTED, 'reject', 'Отклонено модератором (TG)');

        return $this->page('🚫 Отклонено', sprintf('«%s» отклонён.', $brand->getTitle()), true);
    }

    private function decide(EntityManagerInterface $em, Brand $brand, string $status, string $verdict, ?string $adminNote = null): void
    {
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        if ($moderation !== null) {
            $moderation->setStatus($status);
            $moderation->setVerdict($verdict);
            $moderation->setDecidedAt(new \DateTime());
            $moderation->setDecidedVia('tg');
            if ($adminNote !== null) {
                $moderation->setAdminNote($adminNote);
            }
        }
        $em->flush();
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
