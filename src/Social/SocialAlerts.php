<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\SocialPost;
use App\Notification\AdminNotifier;

/** Алерты админу по публикации соцсетей. Сбой отправки ничего не роняет. */
final class SocialAlerts
{
    public function __construct(private readonly AdminNotifier $notifier)
    {
    }

    /** Ровно на переходе в publish_failed (дальше пост из очереди выпадает молча). */
    public function publishFailed(SocialPost $post): void
    {
        $ch = $post->getChannel();
        $this->send(sprintf(
            "⚠️ <b>Соцсети: публикация не удалась</b> (попыток: %d)\n%s [%s]\nПост #%d, рубрика: %s\nПлан: %s\n<code>%s</code>",
            $post->getPublishAttempts(),
            htmlspecialchars((string) $ch->getName(), ENT_QUOTES),
            htmlspecialchars((string) $ch->getPlatform(), ENT_QUOTES),
            $post->getId(),
            htmlspecialchars($post->getRubric(), ENT_QUOTES),
            $post->getScheduledAt()?->format('d.m.Y H:i') ?? '—',
            htmlspecialchars(mb_substr((string) $post->getLastError(), 0, 300), ENT_QUOTES),
        ));
    }

    /** castbase не знает, вышел ли пост: НЕ повторять, сверить профиль руками (иначе дубль). */
    public function outcomeUnknown(SocialPost $post): void
    {
        $ch = $post->getChannel();
        $this->send(sprintf(
            "🛑 <b>Соцсети: исход публикации неизвестен</b>\n%s [%s]\nПост #%d, рубрика: %s\nПроверь профиль руками: вышел ли пост. НЕ ставь заново, пока не проверишь (будет дубль).\n<code>%s</code>",
            htmlspecialchars((string) $ch->getName(), ENT_QUOTES),
            htmlspecialchars((string) $ch->getPlatform(), ENT_QUOTES),
            $post->getId(),
            htmlspecialchars($post->getRubric(), ENT_QUOTES),
            htmlspecialchars(mb_substr((string) $post->getLastError(), 0, 300), ENT_QUOTES),
        ));
    }

    public function text(string $html): void
    {
        $this->send($html);
    }

    private function send(string $html): void
    {
        if (!$this->notifier->isEnabled()) {
            return;
        }
        try {
            $this->notifier->send($html);
        } catch (\Throwable) {
            // уведомление не должно ломать команду
        }
    }
}
