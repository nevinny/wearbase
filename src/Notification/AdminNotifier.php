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

    /**
     * Уведомление с одной inline-кнопкой-действием (callback_data обрабатывает TelegramController).
     * Напр. «🚫 Скрыть с публикации» под сообщением об опубликованном бренде.
     */
    public function sendWithButton(string $html, string $buttonText, string $callbackData): void
    {
        if ($this->adminChatId === '') {
            return;
        }
        $this->telegram->send($this->adminChatId, $html, [
            'inline_keyboard' => [[['text' => $buttonText, 'callback_data' => $callbackData]]],
        ]);
    }

    /**
     * Уведомление с НЕСКОЛЬКИМИ inline-кнопками (по одной на строку), напр. список
     * опубликованных брендов, у каждого «🚫 Скрыть».
     * @param list<array{text:string, data:string}> $buttons
     */
    public function sendWithButtons(string $html, array $buttons): void
    {
        if ($this->adminChatId === '' || $buttons === []) {
            return;
        }
        $rows = array_map(
            static fn(array $b) => [['text' => $b['text'], 'callback_data' => $b['data']]],
            $buttons,
        );
        $this->telegram->send($this->adminChatId, $html, ['inline_keyboard' => $rows]);
    }

    /** Тот ли это чат, что наш админский (защита callback'ов: чужой не должен скрывать бренды). */
    public function isAdminChat(string $chatId): bool
    {
        return $this->adminChatId !== '' && $chatId === $this->adminChatId;
    }
}
