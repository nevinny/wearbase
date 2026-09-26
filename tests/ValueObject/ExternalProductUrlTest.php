<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\ValueObject\ExternalProductUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalProductUrlTest extends TestCase
{
    public function testNormalizesHostAndDropsFragment(): void
    {
        self::assertSame(
            'https://shop.example.test/Product/1?ref=child',
            ExternalProductUrl::fromString('https://SHOP.EXAMPLE.TEST/Product/1?ref=child#private-state')->toString(),
        );
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExternalProductUrl::fromString($url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'http' => ['http://shop.example.test/item'];
        yield 'credentials' => ['https://user:secret@shop.example.test/item'];
        yield 'custom port' => ['https://shop.example.test:8443/item'];
        yield 'localhost' => ['https://localhost/item'];
        yield 'private IP' => ['https://127.0.0.1/item'];
        yield 'backslash ambiguity' => ['https://shop.example.test\\@internal.test/item'];
        yield 'control character' => ["https://shop.example.test/item\nnext"];
    }
}
