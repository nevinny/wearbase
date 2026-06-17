<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Публикация в Instagram через self-host Postiz (standalone Instagram-Login — без FB-страницы,
 * см. docs/marketing_instagram.md §4а). target канала = id интеграции в Postiz.
 * ⚠️ Postiz должен крутиться на хосте с egress к Meta (РФ-прод/Mac заблокированы без VPN).
 *
 * ⚠️ Точный контракт Postiz public API зависит от версии инстанса — эндпоинт/поля проверить
 * при развёртывании (Фаза 0). Без POSTIZ_URL/POSTIZ_API_KEY — публикация невозможна.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $postizUrl,
        private readonly string $postizApiKey,
    ) {
    }

    public function platform(): string
    {
        return SocialChannel::PLATFORM_IG;
    }

    public function publish(SocialChannel $channel, SocialPost $post, ?string $mediaAbsPath): string
    {
        if (trim($this->postizUrl) === '' || trim($this->postizApiKey) === '') {
            throw new \RuntimeException('POSTIZ_URL/POSTIZ_API_KEY не заданы — публикация в Instagram невозможна.');
        }
        if ($mediaAbsPath === null) {
            throw new \RuntimeException('Instagram требует медиа — текстовый пост невозможен.');
        }
        $integrationId = $channel->getTarget();
        if ($integrationId === '') {
            throw new \RuntimeException('У IG-канала пустой target (нужен id интеграции Postiz).');
        }

        // IG: кликабельных ссылок в подписи нет — ссылка живёт в профиле; URL в текст не вставляем.
        $content = (string) $post->getCaption();
        if ($post->getCtaLabel() !== null) {
            $content .= "\n\n" . $post->getCtaLabel() . ' — ссылка в профиле';
        }

        $response = $this->httpClient->request('POST', rtrim($this->postizUrl, '/') . '/api/public/v1/posts', [
            'headers' => ['Authorization' => $this->postizApiKey],
            'json'    => [
                'type'         => 'now',
                'integrations' => [['id' => $integrationId]],
                'content'      => $content,
                // media — путь/URL медиа; конкретный формат уточнить под версию Postiz (Фаза 0)
                'media'        => [['path' => $mediaAbsPath]],
            ],
            'timeout' => 90,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 300) {
            throw new \RuntimeException("Postiz publish error: HTTP {$status} " . mb_substr($response->getContent(false), 0, 300));
        }

        $data = $response->toArray(false);

        return (string) ($data['id'] ?? $data['postId'] ?? 'postiz-ok');
    }
}
