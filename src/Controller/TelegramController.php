<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Notification\AdminNotifier;
use App\Notification\TelegramNotifier;
use App\Service\Agent\BrandUnpublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/telegram', name: 'telegram_')]
class TelegramController extends AbstractController
{
    #[Route('/webhook', name: 'webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        EntityManagerInterface $em,
        TelegramNotifier $telegramNotifier,
        AdminNotifier $adminNotifier,
        BrandUnpublisher $unpublisher,
        \Psr\Log\LoggerInterface $telegramLogger,
    ): Response {
        $body = json_decode($request->getContent(), true);

        // Нажатие inline-кнопки (напр. «🚫 Скрыть с публикации» под уведомлением о публикации).
        if (is_array($body) && isset($body['callback_query'])) {
            return $this->handleCallback($body['callback_query'], $telegramNotifier, $adminNotifier, $unpublisher, $telegramLogger);
        }

        if (!$body || !isset($body['message'])) {
            return new JsonResponse(['ok' => false]);
        }

        $message  = $body['message'];
        $chatId   = (string) ($message['chat']['id'] ?? '');
        $text     = trim($message['text'] ?? '');
        $username = $message['from']['username'] ?? '';

        if (str_starts_with($text, '/start ')) {
            $token = substr($text, 7);
            $user = $em->getRepository(User::class)->findOneBy(['telegramLinkToken' => $token]);
            if ($user) {
                $user->setTelegramChatId($chatId);
                $user->setTelegramLinkToken(null);
                $em->flush();
                $telegramNotifier->send($chatId, '✅ Telegram привязан к вашему аккаунту WEARBASE!');
            } else {
                $telegramNotifier->send($chatId, '❌ Ссылка устарела. Сгенерируйте новую в настройках безопасности на сайте.');
            }
        } elseif ($text === '/start') {
            $telegramNotifier->send($chatId, 'Добро пожаловать в WEARBASE! 🎉

Для привязки Telegram к вашему аккаунту:
1. Откройте настройки безопасности на сайте
2. Нажмите «Привязать Telegram»

После этого вы будете получать уведомления о заказах и других событиях.');
        }

        return new JsonResponse(['ok' => true]);
    }

    /** Обработка нажатия inline-кнопки. Сейчас: «unpub:<id>» — скрыть бренд с публикации. */
    private function handleCallback(
        array $cq,
        TelegramNotifier $telegram,
        AdminNotifier $adminNotifier,
        BrandUnpublisher $unpublisher,
        \Psr\Log\LoggerInterface $telegramLogger,
    ): Response {
        $cqId    = (string) ($cq['id'] ?? '');
        $data    = (string) ($cq['data'] ?? '');
        $chatId  = (string) ($cq['message']['chat']['id'] ?? $cq['from']['id'] ?? '');
        $msgId   = $cq['message']['message_id'] ?? null;
        $msgText = (string) ($cq['message']['text'] ?? '');

        $telegramLogger->info('TG callback', ['chat' => $chatId, 'data' => $data, 'admin' => $adminNotifier->isAdminChat($chatId)]);

        // Безопасность: скрывать бренды может ТОЛЬКО наш админ-чат (вебхук публичный).
        if (!$adminNotifier->isAdminChat($chatId)) {
            $telegramLogger->warning('TG callback от не-админ чата отклонён', ['chat' => $chatId, 'data' => $data]);
            $telegram->answerCallbackQuery($cqId, 'Не авторизовано');
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($data, 'unpub:')) {
            $res = $unpublisher->hide((int) substr($data, 6));
            $telegramLogger->info('TG hide-кнопка', ['data' => $data, 'ok' => $res['ok'], 'title' => $res['title'] ?? '', 'result' => $res['message']]);
            $telegram->answerCallbackQuery($cqId, $res['ok'] ? '🚫 Скрыт' : 'Не удалось');
            if ($msgId !== null) {
                $telegram->editMessageText($chatId, (int) $msgId, $msgText . "\n\n🚫 <b>Скрыт:</b> {$res['message']}");
            }
        } else {
            $telegram->answerCallbackQuery($cqId, '');
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/link', name: 'link')]
    public function link(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('telegram/link.html.twig', [
            'user' => $user,
        ]);
    }
}
