<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\SecretCipher;
use App\Service\Social\PublicMediaHost;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Публикация в Instagram через официальный Instagram Graph API (контейнерная публикация:
 * создать медиа-контейнер → поллинг статуса → опубликовать). target канала = IG Business
 * Account id, токен — System User Token (бессрочный), хранится зашифрованным (SecretCipher).
 *
 * Graph API требует публичный URL картинки (не файл) — конвертация PNG→JPEG и заливка на
 * прод делает PublicMediaHost.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    private const API_BASE = 'https://graph.facebook.com/v22.0';
    private const POLL_MAX_ATTEMPTS = 12;
    private const POLL_SLEEP_SEC = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
        private readonly PublicMediaHost $mediaHost,
    ) {
    }

    public function platform(): string
    {
        return SocialChannel::PLATFORM_IG;
    }

    public function publish(SocialChannel $channel, SocialPost $post, ?string $mediaAbsPath): string
    {
        if ($mediaAbsPath === null || !is_file($mediaAbsPath)) {
            throw new \RuntimeException('Instagram требует медиа — текстовый пост невозможен.');
        }

        $igUserId = $channel->getTarget();
        if ($igUserId === '') {
            throw new \RuntimeException('У IG-канала пустой target (нужен Instagram Business Account id).');
        }

        $enc = $channel->getTokenEnc();
        if ($enc === null || $enc === '') {
            throw new \RuntimeException('У IG-канала нет токена (System User Token).');
        }
        $token = $this->cipher->decrypt($enc);

        $imageUrl = $this->mediaHost->publicJpegUrl($mediaAbsPath);

        // IG: кликабельных ссылок в подписи нет — ссылка живёт в профиле; URL в текст не вставляем.
        $caption = (string) $post->getCaption();
        if ($post->getCtaLabel() !== null) {
            $caption .= "\n\n" . $post->getCtaLabel() . ' — ссылка в профиле';
        }

        $creationId = $this->createContainer($igUserId, $imageUrl, $caption, $token);
        $this->pollUntilFinished($creationId, $token);

        return $this->publishContainer($igUserId, $creationId, $token);
    }

    private function createContainer(string $igUserId, string $imageUrl, string $caption, string $token): string
    {
        $response = $this->httpClient->request('POST', self::API_BASE . "/{$igUserId}/media", [
            'body'    => [
                'image_url'    => $imageUrl,
                'caption'      => $caption,
                'access_token' => $token,
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        $this->assertNoError($data, 'media (create container)');

        $creationId = $data['id'] ?? null;
        if ($creationId === null) {
            throw new \RuntimeException('IG media create не вернул id: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return (string) $creationId;
    }

    private function pollUntilFinished(string $creationId, string $token): void
    {
        for ($attempt = 1; $attempt <= self::POLL_MAX_ATTEMPTS; $attempt++) {
            $response = $this->httpClient->request('GET', self::API_BASE . "/{$creationId}", [
                'query'   => [
                    'fields'       => 'status_code',
                    'access_token' => $token,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray(false);
            $this->assertNoError($data, 'media status poll');

            $status = $data['status_code'] ?? null;
            if ($status === 'FINISHED') {
                return;
            }
            if ($status !== 'IN_PROGRESS') {
                throw new \RuntimeException('IG media поллинг вернул неожиданный статус: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            }

            sleep(self::POLL_SLEEP_SEC);
        }

        throw new \RuntimeException("IG media контейнер {$creationId} не готов после " . self::POLL_MAX_ATTEMPTS . ' попыток поллинга.');
    }

    private function publishContainer(string $igUserId, string $creationId, string $token): string
    {
        $response = $this->httpClient->request('POST', self::API_BASE . "/{$igUserId}/media_publish", [
            'body'    => [
                'creation_id'  => $creationId,
                'access_token' => $token,
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        $this->assertNoError($data, 'media_publish');

        $publishedId = $data['id'] ?? null;
        if ($publishedId === null) {
            throw new \RuntimeException('IG media_publish не вернул id: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return (string) $publishedId;
    }

    /** @param array<string, mixed> $data */
    private function assertNoError(array $data, string $step): void
    {
        if (!isset($data['error'])) {
            return;
        }

        $message = $data['error']['message'] ?? 'unknown';
        $code = $data['error']['code'] ?? null;
        $suffix = $code !== null ? " (code {$code})" : '';

        throw new \RuntimeException("IG {$step} error: {$message}{$suffix}");
    }
}
