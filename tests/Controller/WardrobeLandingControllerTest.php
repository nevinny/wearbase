<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WardrobeLandingControllerTest extends WebTestCase
{
    public function testLandingIsPublicAndLinksToWardrobeApp(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/ru/wardrobe');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Покупайте реже');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
        self::assertSelectorExists('link[rel="canonical"][href$="/ru/wardrobe"]');
        self::assertSelectorExists('link[rel="manifest"][href="/manifest.webmanifest"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"][href="/images/pwa/icon-180.png"]');
        self::assertSelectorExists('meta[name="apple-mobile-web-app-capable"][content="yes"]');
        self::assertSelectorExists('script[src="/pwa-register.js"]');
        self::assertSelectorExists('script[src="/js/tailwind-3.4.17.js"]');
        self::assertSelectorExists('a[href="#install-iphone"]');
        self::assertSelectorExists('footer a[href="/ru/wardrobe"]');
        self::assertCount(2, $crawler->filter('header a[href="/ru/wardrobe"]'));
        self::assertGreaterThanOrEqual(2, $crawler->filter('a[href="/account/wardrobe-app"]')->count());
        self::assertSelectorExists('a[href="/ru/wardrobe/concierge"]');
        self::assertStringNotContainsString('cdn.tailwindcss.com', (string) $client->getResponse()->getContent());
    }
}
