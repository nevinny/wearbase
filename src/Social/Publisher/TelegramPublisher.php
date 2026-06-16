<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Публикация в Telegram-канал через Bot API (@wearbase_bot должен быть админом канала).
 * Использует общий бот-токен (env TELEGRAM_BOT_TOKEN). target канала = @handle или chat_id.
 * ⚠️ Telegram недоступен с РФ-прода → канал должен иметь egress_host=mac.
 */
class TelegramPublisher implements SocialPublisherInterface
{
    private const TG_PHOTO_CAPTION_LIMIT = 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
    ) {
    }

    public function platform(): string
    {
        return SocialChannel::PLATFORM_TG;
    }

    public function publish(SocialChannel $channel, string $caption, ?string $mediaAbsPath): string
    {
        if (trim($this->botToken) === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN не задан — публикация в TG невозможна.');
        }
        $chatId = $channel->getTarget();
        if ($chatId === '') {
            throw new \RuntimeException('У TG-канала пустой target (нужен @handle или chat_id).');
        }

        $withPhoto = $mediaAbsPath !== null
            && is_file($mediaAbsPath)
            && mb_strlen($caption) <= self::TG_PHOTO_CAPTION_LIMIT;

        $result = $withPhoto
            ? $this->call('sendPhoto', [
                'chat_id' => $chatId,
                'caption' => $caption,
                'photo'   => fopen($mediaAbsPath, 'r'),
            ])
            : $this->call('sendMessage', [
                'chat_id'                  => $chatId,
                'text'                     => $caption,
                'disable_web_page_preview' => 'false',
            ]);

        $messageId = $result['result']['message_id'] ?? null;
        if ($messageId === null) {
            throw new \RuntimeException('Telegram не вернул message_id: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        return (string) $messageId;
    }

    /** @return array decoded ответ Telegram */
    private function call(string $method, array $body): array
    {
        $response = $this->httpClient->request('POST', "https://api.telegram.org/bot{$this->botToken}/{$method}", [
            'body'    => $body, // массив с ресурсом → multipart/form-data
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException(sprintf('Telegram %s error: %s', $method, $data['description'] ?? 'unknown'));
        }

        return $data;
    }
}
