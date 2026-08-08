<?php

declare(strict_types=1);

namespace App\Tests\Service\Support;

use App\Service\Support\LinkTypeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Один общий классификатор link_type для клик-аналитики (OutboundClickController) и
 * записи ссылок при премодерации (ModerateTickCommand) — по кейсу на каждую ветку
 * match(), включая дефолт 'website' и 'other' для пустого/нечитаемого хоста.
 */
class LinkTypeClassifierTest extends TestCase
{
    #[DataProvider('urlMatrix')]
    public function testClassify(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkTypeClassifier::classify($url));
    }

    public static function urlMatrix(): iterable
    {
        yield 'instagram'         => ['https://instagram.com/ahsilk', 'instagram'];
        yield 'vk.com'            => ['https://vk.com/ahsilk', 'vk'];
        yield 'vkontakte subdomain' => ['https://m.vkontakte.ru/ahsilk', 'vk'];
        yield 'telegram t.me'     => ['https://t.me/ahsilk', 'telegram'];
        yield 'telegram.me'       => ['https://telegram.me/ahsilk', 'telegram'];
        yield 'youtube.com'       => ['https://youtube.com/@ahsilk', 'youtube'];
        yield 'youtu.be'          => ['https://youtu.be/abc123', 'youtube'];
        yield 'tiktok'            => ['https://tiktok.com/@ahsilk', 'tiktok'];
        yield 'wildberries'       => ['https://www.wildberries.ru/brands/ahsilk', 'marketplace'];
        yield 'ozon'              => ['https://ozon.ru/brand/ahsilk', 'marketplace'];
        yield 'lamoda'            => ['https://www.lamoda.ru/brands/ahsilk', 'marketplace'];
        yield 'market.yandex'     => ['https://market.yandex.ru/brand/ahsilk', 'marketplace'];
        yield 'unrelated domain -> website (дефолт)' => ['https://ahsilk.ru/about', 'website'];
        yield 'empty host -> other' => ['not-a-url', 'other'];
    }
}
