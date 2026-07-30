<?php

declare(strict_types=1);

namespace App\Tests\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\SecretCipher;
use App\Service\Social\PublicMediaHost;
use App\Social\Publisher\InstagramPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Контейнерный флоу IG: одиночная картинка vs карусель (children + media_type=CAROUSEL).
 * HTTP замокан — проверяем именно последовательность и тела запросов к Graph API.
 */
class InstagramPublisherTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, string>}> */
    private array $requests = [];

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
        $this->requests = [];
    }

    public function testSingleImageUsesPlainContainer(): void
    {
        $publisher = $this->publisher(['create-1' => 'cid1']);

        $externalId = $publisher->publish($this->channel(), $this->post(), [$this->tmpFile()]);

        self::assertSame('published-1', $externalId);

        // create → poll → publish, без единого запроса на слайды карусели
        self::assertCount(3, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringEndsWith('/17841400000000000/media', $this->requests[0]['url']);
        self::assertArrayNotHasKey('is_carousel_item', $this->requests[0]['body']);
        self::assertArrayNotHasKey('children', $this->requests[0]['body']);
        self::assertSame('https://media.example/slide-0.jpg', $this->requests[0]['body']['image_url']);
        self::assertStringContainsString('Каталог — ссылка в профиле', $this->requests[0]['body']['caption']);

        self::assertSame('POST', $this->requests[2]['method']);
        self::assertStringEndsWith('/media_publish', $this->requests[2]['url']);
        self::assertSame('cid1', $this->requests[2]['body']['creation_id']);
    }

    public function testCarouselCreatesChildContainersThenParent(): void
    {
        $publisher = $this->publisher(['create-1' => 'child1', 'create-2' => 'child2', 'create-3' => 'child3', 'create-4' => 'parent']);

        $externalId = $publisher->publish(
            $this->channel(),
            $this->post(),
            [$this->tmpFile(), $this->tmpFile(), $this->tmpFile()],
        );

        self::assertSame('published-1', $externalId);

        // 3×(create child + poll) + create parent + poll parent + publish = 9
        self::assertCount(9, $this->requests);

        foreach ([0, 2, 4] as $slide => $i) {
            self::assertSame('true', $this->requests[$i]['body']['is_carousel_item'], "слайд {$slide}");
            self::assertSame("https://media.example/slide-{$slide}.jpg", $this->requests[$i]['body']['image_url']);
            // подпись только у родителя — иначе IG её проигнорирует, а мы не заметим потери
            self::assertArrayNotHasKey('caption', $this->requests[$i]['body'], "слайд {$slide}");
            self::assertStringContainsString('/child' . ($slide + 1) . '?fields=status_code', $this->requests[$i + 1]['url']);
        }

        $parent = $this->requests[6];
        self::assertSame('CAROUSEL', $parent['body']['media_type']);
        self::assertSame('child1,child2,child3', $parent['body']['children']);
        self::assertStringContainsString('Три слайда', $parent['body']['caption']);
        self::assertArrayNotHasKey('image_url', $parent['body']);

        self::assertStringEndsWith('/media_publish', $this->requests[8]['url']);
        self::assertSame('parent', $this->requests[8]['body']['creation_id']);
    }

    public function testReelsUsesVideoContainer(): void
    {
        $publisher = $this->publisher(['create-1' => 'reel1']);
        $post = $this->post()->setMediaType(SocialPost::MEDIA_REELS);

        $externalId = $publisher->publish($this->channel(), $post, [$this->tmpFile()]);

        self::assertSame('published-1', $externalId);
        self::assertCount(3, $this->requests);

        $container = $this->requests[0]['body'];
        self::assertSame('REELS', $container['media_type']);
        // Видео уходит как video_url (не image_url) и без конвертации в JPEG.
        self::assertSame('https://media.example/video-0.mp4', $container['video_url']);
        self::assertArrayNotHasKey('image_url', $container);
        self::assertSame('true', $container['share_to_feed']);
        self::assertStringContainsString('Три слайда', $container['caption']);
    }

    public function testMoreThanTenSlidesRefusedWithoutAnyRequest(): void
    {
        $publisher = $this->publisher([]);
        $paths = [];
        for ($i = 0; $i < 11; $i++) {
            $paths[] = $this->tmpFile();
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/максимум 10 слайдов/u');

        try {
            $publisher->publish($this->channel(), $this->post(), $paths);
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    public function testNoMediaIsRefused(): void
    {
        $publisher = $this->publisher([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/требует медиа/u');

        $publisher->publish($this->channel(), $this->post(), []);
    }

    public function testMissingFilesAreSkippedAndEmptyResultRefused(): void
    {
        $publisher = $this->publisher([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/требует медиа/u');

        $publisher->publish($this->channel(), $this->post(), ['/nope/gone.png']);
    }

    /** @param array<string, string> $containerIds create-N → возвращаемый id контейнера */
    private function publisher(array $containerIds): InstagramPublisher
    {
        $created = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$created, $containerIds): MockResponse {
            $body = $this->decodeBody($options['body'] ?? null);
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

            if (str_ends_with($url, '/media_publish')) {
                return new MockResponse(json_encode(['id' => 'published-1']));
            }
            if (str_ends_with($url, '/media')) {
                $created++;
                $id = $containerIds["create-{$created}"] ?? throw new \LogicException("Нет мока для create-{$created}");

                return new MockResponse(json_encode(['id' => $id]));
            }

            // поллинг статуса контейнера
            return new MockResponse(json_encode(['status_code' => 'FINISHED']));
        });

        $mediaHost = $this->createMock(PublicMediaHost::class);
        $slide = 0;
        $mediaHost->method('publicJpegUrl')->willReturnCallback(
            static function () use (&$slide): string {
                return 'https://media.example/slide-' . $slide++ . '.jpg';
            },
        );
        $video = 0;
        $mediaHost->method('publicUrl')->willReturnCallback(
            static function () use (&$video): string {
                return 'https://media.example/video-' . $video++ . '.mp4';
            },
        );

        return new InstagramPublisher($client, new SecretCipher($this->key()), $mediaHost);
    }

    private function channel(): SocialChannel
    {
        $cipher = new SecretCipher($this->key());

        return (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_IG)
            ->setTarget('17841400000000000')
            ->setTokenEnc($cipher->encrypt('ig-token'));
    }

    private function post(): SocialPost
    {
        return (new SocialPost())
            ->setCaption('Три слайда про российские бренды')
            ->setCtaLabel('Каталог');
    }

    private function tmpFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ig-slide-');
        file_put_contents($path, 'x');
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function key(): string
    {
        return base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /** @return array<string, string> */
    private function decodeBody(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }
        if (!is_string($body) || $body === '') {
            return [];
        }

        parse_str($body, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
