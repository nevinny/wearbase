<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\Notification;
use App\Entity\NotificationSettings;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand/notifications', name: 'brand_notification')]
class BrandNotificationsController extends BrandDashboardController
{
    private const EVENT_TYPES = [
        Notification::TYPE_ORDER_NEW => 'Новый заказ',
        Notification::TYPE_ORDER_STATUS => 'Статус заказа',
        Notification::TYPE_ORDER_SHIPPED => 'Заказ отправлен',
        Notification::TYPE_ORDER_DELIVERED => 'Заказ доставлен',
        Notification::TYPE_BRAND_INVITE => 'Приглашение в команду',
        Notification::TYPE_PRODUCT_LOW_STOCK => 'Остатки на складе',
        Notification::TYPE_WEEKLY_STATS => 'Еженедельная статистика',
        Notification::TYPE_SYSTEM => 'Системные',
    ];

    private const CHANNELS = ['channelEmail', 'channelTelegram', 'channelInapp', 'channelPush'];

    #[Route('', name: '')]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        NotificationSettingsRepository $settingsRepo,
        NotificationRepository $notificationRepo,
    ): Response {
        $brand = $this->getActiveBrand();
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $existingSettings = $settingsRepo->findByUser($user);
        $indexed = [];
        foreach ($existingSettings as $s) {
            $indexed[$s->getEventType()] = $s;
        }

        if ($request->isMethod('POST')) {
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
            return $this->redirectToRoute('brand_notification');
        }

        return $this->render('brand_lk/notifications.html.twig', [
            'brand'          => $brand,
            'eventTypes'     => self::EVENT_TYPES,
            'channels'       => self::CHANNELS,
            'settings'       => $indexed,
            'unreadCount'    => $notificationRepo->countUnread($user),
        ]);
    }
}
