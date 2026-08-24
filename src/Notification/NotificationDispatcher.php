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
            $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = "<b>{$safeTitle}</b>";
            if ($body) {
                $text .= "\n\n".htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $this->telegramNotifier->send($recipient->getTelegramChatId(), $text);
        }
    }

    /**
     * Persist-only delivery for use inside an application transaction.
     *
     * @param array<string, mixed>|null $data
     */
    public function dispatchInApp(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?array $data = null,
    ): void {
        $this->createInApp($recipient, $type, $title, $body, $data);
    }

    /** @param array<string, mixed>|null $data */
    public function dispatchInAppOnce(
        User $recipient,
        string $type,
        string $dedupeKey,
        string $title,
        ?string $body = null,
        ?array $data = null,
    ): void {
        $settings = $this->settingsRepo->findOneBy(['user' => $recipient, 'eventType' => $type]);
        if ($settings !== null && !$settings->isChannelInapp()) {
            return;
        }
        if ($this->em->getRepository(Notification::class)->findOneBy([
            'recipient' => $recipient,
            'dedupeKey' => $dedupeKey,
        ]) !== null) {
            return;
        }

        $notification = $this->createInApp($recipient, $type, $title, $body, $data);
        $notification->setDedupeKey($dedupeKey);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function createInApp(User $recipient, string $type, string $title, ?string $body = null, ?array $data = null): Notification
    {
        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setData($data);
        $notification->setChannel(Notification::CHANNEL_INAPP);

        $this->em->persist($notification);

        return $notification;
    }
}
