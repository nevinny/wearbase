<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\ExternalNotificationOutbox;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

readonly class NotificationDispatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationSettingsRepository $settingsRepo,
        private Environment $twig,
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
        ?string $dedupeKey = null,
    ): void {
        $settings = $this->settingsRepo->findOneBy([
            'user' => $recipient,
            'eventType' => $type,
        ]);

        $channels = [
            'inapp'    => $settings ? $settings->isChannelInapp() : true,
            'email'    => $settings ? $settings->isChannelEmail() : true,
            'telegram' => $settings ? $settings->isChannelTelegram() : false,
            'push'     => $settings ? $settings->isChannelPush() : false,
        ];

        $dedupeKey ??= bin2hex(random_bytes(16));
        if ($channels['inapp']) {
            $this->createInApp($recipient, $type, $title, $body, $data)->setDedupeKey($dedupeKey);
        }

        if ($channels['email'] && $emailTemplate) {
            $context = $emailContext ?? [];
            $context['user'] = $recipient;
            $this->em->persist(new ExternalNotificationOutbox($recipient, Notification::CHANNEL_EMAIL, $type, $dedupeKey.':email', [
                'to' => (string) $recipient->getEmail(),
                'name' => $recipient->getFullName(),
                'subject' => $title,
                'html' => $this->twig->render("emails/{$emailTemplate}.html.twig", $context),
            ]));
        }

        if ($channels['telegram'] && $recipient->getTelegramChatId()) {
            $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = "<b>{$safeTitle}</b>";
            if ($body) {
                $text .= "\n\n".htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $this->em->persist(new ExternalNotificationOutbox($recipient, Notification::CHANNEL_TELEGRAM, $type, $dedupeKey.':telegram', [
                'chatId' => $recipient->getTelegramChatId(),
                'text' => $text,
            ]));
        }

        if ($channels['push']) {
            $notification = (new Notification())->setData($data);
            $this->em->persist(new ExternalNotificationOutbox($recipient, Notification::CHANNEL_PUSH, $type, $dedupeKey.':push', [
                'title' => $title,
                'body' => $body,
                'url' => $notification->getSafeAccountUrl() ?? '/account/notifications',
            ]));
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
