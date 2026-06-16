<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class TelegramNotifier
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $botToken,
        private LoggerInterface $logger,
    ) {}

    /** @param array<mixed>|null $replyMarkup inline-клавиатура (reply_markup), напр. кнопка «Скрыть» */
    public function send(string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        if ($this->botToken === '' || $chatId === '') {
            return false;
        }

        try {
            $json = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
            if ($replyMarkup !== null) {
                $json['reply_markup'] = $replyMarkup;
            }
            $response = $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/sendMessage', ['json' => $json]);

            $data = $response->toArray();
            if (!($data['ok'] ?? false)) {
                $this->logger->warning('Telegram notification rejected', ['chat_id' => $chatId, 'response' => $data]);
            }
            return $data['ok'] ?? false;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram notification failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Ответ на нажатие inline-кнопки (всплывашка у пользователя). */
    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): bool
    {
        if ($this->botToken === '') {
            return false;
        }
        try {
            $data = $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/answerCallbackQuery', [
                'json' => ['callback_query_id' => $callbackQueryId, 'text' => $text],
            ])->toArray(false);
            return $data['ok'] ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Перезаписать текст сообщения (убрать кнопку после действия). */
    public function editMessageText(string $chatId, int $messageId, string $text): bool
    {
        if ($this->botToken === '') {
            return false;
        }
        try {
            $data = $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/editMessageText', [
                'json' => ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'],
            ])->toArray(false);
            return $data['ok'] ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setWebhook(string $url): bool
    {
        if ($this->botToken === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/setWebhook', [
                'json' => ['url' => $url],
            ]);

            $data = $response->toArray();
            return $data['ok'] ?? false;
        } catch (\Throwable) {
            return false;
        }
    }
}
