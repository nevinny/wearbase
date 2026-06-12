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

    public function send(string $chatId, string $text): bool
    {
        if ($this->botToken === '' || $chatId === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/sendMessage', [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ],
            ]);

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
