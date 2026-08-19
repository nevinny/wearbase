<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WardrobeConciergeLandingControllerTest extends WebTestCase
{
    public function testConciergeLandingIsPublicAndHasSeparatePositioning(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/ru/wardrobe/concierge');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Гардероб');
        self::assertSelectorTextContains('body', 'Не приложение. Сервис.');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
        self::assertSelectorExists('link[rel="canonical"][href$="/ru/wardrobe/concierge"]');
        self::assertSelectorExists('meta[property="og:image"][content$="/images/landing/wardrobe-private-archive.webp"]');
        self::assertGreaterThanOrEqual(2, $crawler->filter('a[href*="t.me/wearbase_bot"]')->count());
        self::assertSelectorExists('a[href="/account/wardrobe-app"]');
    }
}
