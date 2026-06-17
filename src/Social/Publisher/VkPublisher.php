<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Публикация в VK-сообщество через wall.post (нативный API; работает с РФ-прода).
 * Токен — community access token, хранится зашифрованным (SecretCipher). target = owner_id
 * сообщества (отрицательный). MVP: текстовый пост; вложение фото — TODO (photos.* flow).
 */
class VkPublisher implements SocialPublisherInterface
{
    private const API_VERSION = '5.199';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function platform(): string
    {
        return SocialChannel::PLATFORM_VK;
    }

    public function publish(SocialChannel $channel, SocialPost $post, ?string $mediaAbsPath): string
    {
        $enc = $channel->getTokenEnc();
        if ($enc === null || $enc === '') {
            throw new \RuntimeException('У VK-канала нет токена (community access token).');
        }
        $ownerId = $channel->getTarget();
        if ($ownerId === '') {
            throw new \RuntimeException('У VK-канала пустой target (нужен owner_id сообщества).');
        }

        // VK автолинкует URL — CTA как «текст: ссылка» (UTM сохраняется).
        $message = (string) $post->getCaption();
        if ($post->getCtaUrl() !== null) {
            $message .= "\n\n" . trim(($post->getCtaLabel() ?? '') . ': ' . $post->getCtaUrl(), ': ');
        }

        $response = $this->httpClient->request('POST', 'https://api.vk.com/method/wall.post', [
            'body' => [
                'owner_id'     => $ownerId,
                'from_group'   => 1,
                'message'      => $message,
                'access_token' => $this->cipher->decrypt($enc),
                'v'            => self::API_VERSION,
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error'])) {
            throw new \RuntimeException('VK wall.post error: ' . ($data['error']['error_msg'] ?? 'unknown'));
        }

        $postId = $data['response']['post_id'] ?? null;
        if ($postId === null) {
            throw new \RuntimeException('VK не вернул post_id: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return $ownerId . '_' . $postId;
    }
}
