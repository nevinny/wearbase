<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Notification\TelegramNotifier;
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
    ): Response {
        $body = json_decode($request->getContent(), true);
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
