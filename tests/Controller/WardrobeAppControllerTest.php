<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\FamilyService;
use App\Service\PurchaseRequestService;

class WardrobeAppControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testLoginStaysInsideStandaloneAppScope(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/public_html/manifest.webmanifest'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('/', $manifest['scope']);
        self::assertStringContainsString("scope: '/'", (string) file_get_contents(dirname(__DIR__, 2).'/public_html/pwa-register.js'));
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseRedirects('/login', 302);
    }

    public function testDashboardShowsWardrobeAndQuickActions(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Что хотите сделать?');
        $this->assertSelectorTextContains('body', 'Мой гардероб');
        $this->assertSelectorExists('a[href="/account/wardrobe/new"]');
        $this->assertSelectorExists('a[href="/account/wardrobe/outfits"]');
        $this->assertSelectorExists('a[href="/account/wardrobe/statistics"]');
        $this->assertSelectorTextContains('body', 'Состав семьи');
        $this->assertSelectorExists('form[action="/account/family/invite"] input[name="role"][value="child"]');
        $this->assertSelectorExists('form[action="/account/family/invite"] input[name="role"][value="parent"]');
        $this->assertSelectorExists('#family-main.family-safe-content.pt-5:not(.py-5)');
    }

    public function testParentSeesChildWardrobe(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-app-parent@test.local');
        $parent->setFirstName('Родитель');

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Саша');
        $client->loginUser($parent);

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Саша');
        $this->assertSelectorExists(sprintf('a[href="/account/wardrobe?member=%d"]', $child->getId()));
    }

    public function testChildSeesFamilyButCannotOpenParentWardrobe(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-app-family-parent@test.local');
        $parent->setFirstName('Мария');

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Лиза');
        $client->loginUser($child);

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Мария');
        $this->assertSelectorTextContains('body', 'Лиза');
        $this->assertSelectorExists(sprintf('a[href="/account/wardrobe?member=%d"]', $child->getId()));
        $this->assertSelectorNotExists(sprintf('a[href="/account/wardrobe?member=%d"]', $parent->getId()));
        $this->assertSelectorNotExists('a[href="/account/family/add"]');
        $this->assertSelectorNotExists('form[action="/account/family/invite"]');
        $this->assertSelectorExists('#share-wardrobe-app[data-share-url$="/ru/wardrobe"]');
    }

    public function testParentDashboardShowsPendingPurchaseCount(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-app-purchase-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Маша');
        static::getContainer()->get(PurchaseRequestService::class)->create(
            $child,
            $child,
            'https://shop.example.test/item/dashboard',
            null,
            '1500',
        );
        $client->loginUser($parent);

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('a[href="/account/purchases"]', '1 ждут решения');
    }
}
