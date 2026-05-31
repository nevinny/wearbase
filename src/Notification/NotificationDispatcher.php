<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class NotificationDispatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private EmailNotifier $emailNotifier,
        private TelegramNotifier $telegramNotifier,
        private NotificationSettingsRepository $settingsRepo,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function dispatch(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?array $data = null,
        ?string $emailTemplate = null,
        ?array $emailContext = [],
    ): void {
        $settings = $this->settingsRepo->findOneBy([
            'user' => $recipient,
            'eventType' => $type,
        ]);

        $channels = [
            'inapp'    => $settings ? $settings->isChannelInapp() : true,
            'email'    => $settings ? $settings->isChannelEmail() : true,
            'telegram' => $settings ? $settings->isChannelTelegram() : false,
        ];

        if ($channels['inapp']) {
            $this->createInApp($recipient, $type, $title, $body, $data);
        }

        if ($channels['email'] && $emailTemplate) {
            $this->emailNotifier->send($recipient, $title, $emailTemplate, $emailContext);
        }

        if ($channels['telegram'] && $recipient->getTelegramChatId()) {
            $text = "<b>{$title}</b>";
            if ($body) {
                $text .= "\n\n{$body}";
            }
            $this->telegramNotifier->send($recipient->getTelegramChatId(), $text);
        }
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function createInApp(User $recipient, string $type, string $title, ?string $body = null, ?array $data = null): void
    {
        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setData($data);
        $notification->setChannel(Notification::CHANNEL_INAPP);

        $this->em->persist($notification);
    }
}
