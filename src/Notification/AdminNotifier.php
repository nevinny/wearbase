<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Уведомления администратору проекта в Telegram (отдельный чат/канал).
 * Если ADMIN_TELEGRAM_CHAT_ID не задан — деградирует в no-op.
 */
readonly class AdminNotifier
{
    public function __construct(
        private TelegramNotifier $telegram,
        private string $adminChatId = '',
    ) {}

    public function isEnabled(): bool
    {
        return $this->adminChatId !== '';
    }

    public function send(string $html): void
    {
        if ($this->adminChatId === '') {
            return;
        }
        $this->telegram->send($this->adminChatId, $html);
    }
}
