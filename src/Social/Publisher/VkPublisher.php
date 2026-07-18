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
 * сообщества (отрицательный). Если передан $mediaAbsPath — фото грузится через
 * photos.getWallUploadServer → upload_url → photos.saveWallPhoto и прикрепляется к посту.
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

        $token = $this->cipher->decrypt($enc);

        $wallPostBody = [
            'owner_id'     => $ownerId,
            'from_group'   => 1,
            'message'      => $message,
            'access_token' => $token,
            'v'            => self::API_VERSION,
        ];

        if ($mediaAbsPath !== null && is_file($mediaAbsPath)) {
            // Фото-аплоуд деградирует мягко: не роняем пост, если фото не прикрепилось.
            // Частая причина — community-токен не имеет доступа к photos.* upload (VK требует
            // user-токен админа сообщества с правами photos,wall,groups). С таким токеном тот же
            // флоу отработает без правок кода. При провале — публикуем текстом.
            try {
                $wallPostBody['attachment'] = $this->uploadWallPhoto((int) $ownerId, $mediaAbsPath, $token);
            } catch (\Throwable $e) {
                error_log('[VkPublisher] фото не прикреплено, публикую текстом: ' . $e->getMessage());
            }
        }

        $response = $this->httpClient->request('POST', 'https://api.vk.com/method/wall.post', [
            'body'    => $wallPostBody,
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

    /**
     * Стандартный VK wall-photo upload flow: getWallUploadServer → upload → saveWallPhoto.
     * @return string attachment вида "photo{owner_id}_{id}" для wall.post
     */
    private function uploadWallPhoto(int $ownerId, string $mediaAbsPath, string $token): string
    {
        $groupId = abs($ownerId);

        $serverResp = $this->httpClient->request('POST', 'https://api.vk.com/method/photos.getWallUploadServer', [
            'body' => [
                'group_id'     => $groupId,
                'access_token' => $token,
                'v'            => self::API_VERSION,
            ],
            'timeout' => 60,
        ]);
        $serverData = $serverResp->toArray(false);
        if (isset($serverData['error'])) {
            throw new \RuntimeException('VK photos.getWallUploadServer error: ' . ($serverData['error']['error_msg'] ?? 'unknown'));
        }
        $uploadUrl = $serverData['response']['upload_url'] ?? null;
        if ($uploadUrl === null) {
            throw new \RuntimeException('VK photos.getWallUploadServer не вернул upload_url: ' . json_encode($serverData, JSON_UNESCAPED_UNICODE));
        }

        $uploadResp = $this->httpClient->request('POST', $uploadUrl, [
            'body' => [
                'photo' => fopen($mediaAbsPath, 'r'),
            ],
            'timeout' => 60,
        ]);
        $uploadData = $uploadResp->toArray(false);
        if (empty($uploadData['photo']) || $uploadData['photo'] === '[]') {
            throw new \RuntimeException('VK upload_url не вернул фото: ' . json_encode($uploadData, JSON_UNESCAPED_UNICODE));
        }

        $saveResp = $this->httpClient->request('POST', 'https://api.vk.com/method/photos.saveWallPhoto', [
            'body' => [
                'group_id'     => $groupId,
                'server'       => $uploadData['server'],
                'photo'        => $uploadData['photo'],
                'hash'         => $uploadData['hash'],
                'access_token' => $token,
                'v'            => self::API_VERSION,
            ],
            'timeout' => 60,
        ]);
        $saveData = $saveResp->toArray(false);
        if (isset($saveData['error'])) {
            throw new \RuntimeException('VK photos.saveWallPhoto error: ' . ($saveData['error']['error_msg'] ?? 'unknown'));
        }
        $savedPhoto = $saveData['response'][0] ?? null;
        if (!isset($savedPhoto['owner_id'], $savedPhoto['id'])) {
            throw new \RuntimeException('VK photos.saveWallPhoto не вернул фото: ' . json_encode($saveData, JSON_UNESCAPED_UNICODE));
        }

        return 'photo' . $savedPhoto['owner_id'] . '_' . $savedPhoto['id'];
    }
}
