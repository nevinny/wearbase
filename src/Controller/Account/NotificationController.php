<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\Notification;
use App\Entity\NotificationSettings;
use App\Entity\User;
use App\Entity\WebPushSubscription;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSettingsRepository;
use App\Repository\WebPushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/notifications', name: 'account_notification_')]
class NotificationController extends AbstractController
{
    private const EVENT_TYPES = [
        Notification::TYPE_ORDER_NEW => 'Новый заказ',
        Notification::TYPE_ORDER_STATUS => 'Статус заказа',
        Notification::TYPE_ORDER_SHIPPED => 'Заказ отправлен',
        Notification::TYPE_ORDER_DELIVERED => 'Заказ доставлен',
        Notification::TYPE_SYSTEM => 'Системные',
        Notification::TYPE_PURCHASE_REQUEST_NEW => 'Новый запрос на покупку',
        Notification::TYPE_PURCHASE_REQUEST_DECIDED => 'Решение по покупке',
        Notification::TYPE_PURCHASE_FITTING => 'Результат примерки',
        Notification::TYPE_PURCHASE_BOUGHT => 'Вещь выкуплена',
        Notification::TYPE_PURCHASE_REFUSED => 'Отказ после примерки',
        Notification::TYPE_PURCHASE_RETURNED => 'Вещь возвращена',
        Notification::TYPE_PURCHASE_DECISION_REMINDER => 'Напоминание о решении',
        Notification::TYPE_PURCHASE_FITTING_REMINDER => 'Напоминание о примерке',
        Notification::TYPE_PURCHASE_WEAR_REMINDER => 'Оценка новой вещи после носки',
    ];

    private const CHANNELS = ['channelEmail', 'channelInapp', 'channelTelegram', 'channelPush'];
    private const INAPP_ONLY_TYPES = [
        Notification::TYPE_PURCHASE_DECISION_REMINDER,
        Notification::TYPE_PURCHASE_FITTING_REMINDER,
    ];

    #[Route('', name: 'index')]
    public function index(NotificationRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->privateResponse($this->render('account/notifications.html.twig', [
            'notifications' => $repo->findForUser($user),
            'unreadCount'   => $repo->countUnread($user),
        ]));
    }

    #[Route('/settings', name: 'settings')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        NotificationSettingsRepository $settingsRepo,
        WebPushSubscriptionRepository $pushSubscriptions,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(WEB_PUSH_PUBLIC_KEY)%')]
        string $webPushPublicKey,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $existingSettings = $settingsRepo->findByUser($user);
        $indexed = [];
        foreach ($existingSettings as $s) {
            $indexed[$s->getEventType()] = $s;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('notification_settings', $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('account_notification_settings');
            }

            foreach (self::EVENT_TYPES as $eventType => $label) {
                $settings = $indexed[$eventType] ?? null;
                if ($settings === null) {
                    $settings = new NotificationSettings();
                    $settings->setUser($user);
                    $settings->setEventType($eventType);
                    $em->persist($settings);
                }

                $allSettings = $request->request->all('settings');
                foreach (self::CHANNELS as $channel) {
                    $value = (bool) ($allSettings[$eventType][$channel] ?? false);
                    $setter = 'set' . ucfirst($channel);
                    $settings->$setter($value);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Настройки уведомлений сохранены');
            return $this->redirectToRoute('account_notification_settings');
        }

        return $this->privateResponse($this->render('account/notification_settings.html.twig', [
            'eventTypes' => self::EVENT_TYPES,
            'channels'   => self::CHANNELS,
            'settings'   => $indexed,
            'inappOnlyTypes' => self::INAPP_ONLY_TYPES,
            'pushSubscriptions' => $pushSubscriptions->findActiveForUser($user),
            'webPushPublicKey' => $webPushPublicKey,
        ]));
    }

    #[Route('/push-subscriptions', name: 'push_subscribe', methods: ['POST'])]
    public function subscribePush(Request $request, WebPushSubscriptionRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('web_push_subscription', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'invalid_csrf'], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->toArray();
        $endpoint = $payload['endpoint'] ?? null;
        $publicKey = $payload['keys']['p256dh'] ?? null;
        $authToken = $payload['keys']['auth'] ?? null;
        if (!is_string($endpoint) || !str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2048
            || !is_string($publicKey) || strlen($publicKey) > 512 || !preg_match('/^[A-Za-z0-9_-]+$/', $publicKey)
            || !is_string($authToken) || strlen($authToken) > 512 || !preg_match('/^[A-Za-z0-9_-]+$/', $authToken)) {
            return $this->json(['error' => 'invalid_subscription'], 422);
        }
        $subscription = $repo->findByEndpoint($endpoint);
        if ($subscription !== null && $subscription->getUser() !== $user) {
            return $this->json(['error' => 'subscription_conflict'], 409);
        }
        if ($subscription === null) {
            $subscription = new WebPushSubscription($user, $endpoint, $publicKey, $authToken, is_string($payload['contentEncoding'] ?? null) ? $payload['contentEncoding'] : 'aes128gcm');
            $em->persist($subscription);
        } else {
            $subscription->update($endpoint, $publicKey, $authToken, is_string($payload['contentEncoding'] ?? null) ? $payload['contentEncoding'] : 'aes128gcm');
        }
        $em->flush();

        return $this->json(['id' => $subscription->getId()], 201);
    }

    #[Route('/push-subscriptions/{id}', name: 'push_unsubscribe', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function unsubscribePush(int $id, Request $request, WebPushSubscriptionRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('web_push_subscription', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'invalid_csrf'], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $subscription = $repo->find($id);
        if ($subscription === null || $subscription->getUser() !== $user) {
            return $this->json(['error' => 'not_found'], 404);
        }
        $subscription->revoke();
        $em->flush();

        return $this->json(null, 204);
    }

    #[Route('/mark-read/{id}', name: 'mark_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markRead(
        int $id,
        Request $request,
        NotificationRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $notification = $repo->find($id);

        if (!$this->isCsrfTokenValid('notification_mark_read_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        if ($notification && $notification->getRecipient() === $user) {
            $notification->markAsRead();
            $em->flush();
        }

        return $this->redirectToRoute('account_notification_index');
    }

    #[Route('/mark-all-read', name: 'mark_all_read', methods: ['POST'])]
    public function markAllRead(
        NotificationRepository $repo,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('notification_mark_all_read', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        foreach ($repo->findForUser($user) as $notification) {
            if (!$notification->isRead()) {
                $notification->markAsRead();
            }
        }
        $em->flush();

        return $this->redirectToRoute('account_notification_index');
    }

    private function privateResponse(Response $response): Response
    {
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
