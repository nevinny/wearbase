<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Service\Wardrobe\WardrobeRemotePhotoFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WardrobeRemotePhotoFetcherTest extends TestCase
{
    public function testAttachesValidatedWildberriesImage(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $client = new MockHttpClient(new MockResponse($png ?: '', [
            'http_code' => 200,
            'response_headers' => ['content-type: image/png'],
        ]));
        $item = new WardrobeItem();
        $fetcher = new WardrobeRemotePhotoFetcher($client);

        self::assertTrue($fetcher->attachWildberriesPhoto(
            $item,
            'https://basket-01.wbbasket.ru/vol1/part1/1/images/big/1.webp',
        ));
        self::assertNotNull($item->getPhotoFile());
        self::assertFileExists($item->getPhotoFile()->getPathname());

        @unlink($item->getPhotoFile()->getPathname());
    }

    public function testRejectsNonWildberriesHostWithoutRequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('HTTP request must not happen');
        });
        $item = new WardrobeItem();

        self::assertFalse((new WardrobeRemotePhotoFetcher($client))->attachWildberriesPhoto(
            $item,
            'https://example.com/image.jpg',
        ));
        self::assertNull($item->getPhotoFile());
    }

    public function testRejectsNonImageBody(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>not an image</html>', ['http_code' => 200]));
        $item = new WardrobeItem();

        self::assertFalse((new WardrobeRemotePhotoFetcher($client))->attachWildberriesPhoto(
            $item,
            'https://basket-01.wbbasket.ru/image.webp',
        ));
        self::assertNull($item->getPhotoFile());
    }
}
