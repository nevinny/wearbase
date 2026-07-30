<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\SecretCipher;
use App\Service\Social\PublicMediaHost;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Публикация в Instagram через официальный Instagram API with Instagram Login
 * (контейнерная публикация: создать медиа-контейнер → поллинг статуса → опубликовать).
 * Хост — graph.instagram.com (не graph.facebook.com: у нас Instagram Login, без FB-страницы).
 * target канала = IG user id (из /me), токен — долгоживущий IG-токен (~60 дней, продлевается
 * кроном app:social:refresh-ig-token), хранится зашифрованным (SecretCipher).
 *
 * Graph API требует публичный URL картинки (не файл) — конвертация PNG→JPEG и заливка на
 * прод делает PublicMediaHost.
 *
 * Два режима по числу медиа:
 * - 1 картинка — одиночный контейнер (как было);
 * - 2..10 картинок — карусель: на каждый слайд свой контейнер с is_carousel_item=true,
 *   затем родительский контейнер media_type=CAROUSEL со списком children, подпись — только
 *   у родителя. Публикуется один media_publish (родителя).
 */
class InstagramPublisher implements SocialPublisherInterface
{
    private const API_BASE = 'https://graph.instagram.com/v22.0';
    private const POLL_MAX_ATTEMPTS = 12;
    /** Видео Meta транскодирует ощутимо дольше картинки: 30×5с = до 2.5 минут. */
    private const POLL_MAX_ATTEMPTS_VIDEO = 30;
    private const POLL_SLEEP_SEC = 5;

    /** Лимит Instagram на число слайдов в карусели. */
    private const CAROUSEL_MAX_ITEMS = 10;

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

    public function publish(SocialChannel $channel, SocialPost $post, array $mediaAbsPaths): string
    {
        $paths = array_values(array_filter($mediaAbsPaths, 'is_file'));
        if ($paths === []) {
            throw new \RuntimeException('Instagram требует медиа — текстовый пост невозможен.');
        }
        if (count($paths) > self::CAROUSEL_MAX_ITEMS) {
            throw new \RuntimeException(sprintf(
                'В карусели Instagram максимум %d слайдов, передано %d — пост не публикуем, чтобы не терять слайды.',
                self::CAROUSEL_MAX_ITEMS,
                count($paths),
            ));
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

        // IG: кликабельных ссылок в подписи нет — ссылка живёт в профиле; URL в текст не вставляем.
        $caption = (string) $post->getCaption();
        if ($post->getCtaLabel() !== null) {
            $caption .= "\n\n" . $post->getCtaLabel() . ' — ссылка в профиле';
        }

        $isReels = $post->getMediaType() === SocialPost::MEDIA_REELS;

        if ($isReels) {
            $creationId = $this->createReelsContainer($igUserId, $this->mediaHost->publicUrl($paths[0]), $caption, $token);
        } elseif (count($paths) === 1) {
            $creationId = $this->createSingleContainer($igUserId, $this->mediaHost->publicJpegUrl($paths[0]), $caption, $token);
        } else {
            $creationId = $this->createCarouselContainer($igUserId, $paths, $caption, $token);
        }

        // Видео Meta транскодирует минутами, картинка готова почти сразу.
        $this->pollUntilFinished($creationId, $token, $isReels ? self::POLL_MAX_ATTEMPTS_VIDEO : self::POLL_MAX_ATTEMPTS);

        return $this->publishContainer($igUserId, $creationId, $token);
    }

    private function createSingleContainer(string $igUserId, string $imageUrl, string $caption, string $token): string
    {
        return $this->createContainer($igUserId, [
            'image_url' => $imageUrl,
            'caption'   => $caption,
        ], $token, 'media (create container)');
    }

    /**
     * Reels: единственный формат IG с существенной раздачей не-подписчикам.
     * share_to_feed=true — клип виден и в ленте профиля, иначе живёт только во вкладке Reels.
     */
    private function createReelsContainer(string $igUserId, string $videoUrl, string $caption, string $token): string
    {
        return $this->createContainer($igUserId, [
            'media_type'    => 'REELS',
            'video_url'     => $videoUrl,
            'caption'       => $caption,
            'share_to_feed' => 'true',
        ], $token, 'media (create reels container)');
    }

    /**
     * Карусель: сначала контейнер на каждый слайд (is_carousel_item, без подписи), потом
     * родительский CAROUSEL с children. Каждый слайд ждём до FINISHED — незавершённый
     * child ломает создание родителя.
     *
     * Поллинг слайдов последовательный: картиночные контейнеры почти всегда готовы с первого
     * запроса (без sleep), но в худшем случае тик занимает до 10×60с. Осознанный обмен —
     * тик и так под локом и запускается раз в час, а гонка за родителем стоила бы retry поста.
     *
     * @param list<string> $paths
     */
    private function createCarouselContainer(string $igUserId, array $paths, string $caption, string $token): string
    {
        $childIds = [];
        foreach ($paths as $i => $path) {
            $childId = $this->createContainer($igUserId, [
                'image_url'        => $this->mediaHost->publicJpegUrl($path),
                'is_carousel_item' => 'true',
            ], $token, sprintf('media (carousel item %d/%d)', $i + 1, count($paths)));

            $this->pollUntilFinished($childId, $token);
            $childIds[] = $childId;
        }

        return $this->createContainer($igUserId, [
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $childIds),
            'caption'    => $caption,
        ], $token, 'media (create carousel container)');
    }

    /** @param array<string, string> $body поля контейнера помимо access_token */
    private function createContainer(string $igUserId, array $body, string $token, string $step): string
    {
        $response = $this->httpClient->request('POST', self::API_BASE . "/{$igUserId}/media", [
            'body'    => $body + ['access_token' => $token],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        $this->assertNoError($data, $step);

        $creationId = $data['id'] ?? null;
        if ($creationId === null) {
            throw new \RuntimeException('IG media create не вернул id: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return (string) $creationId;
    }

    private function pollUntilFinished(string $creationId, string $token, int $maxAttempts = self::POLL_MAX_ATTEMPTS): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
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

        throw new \RuntimeException("IG media контейнер {$creationId} не готов после {$maxAttempts} попыток поллинга.");
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
