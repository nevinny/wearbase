<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\FamilyService;

class WardrobeAppControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
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
        $this->assertSelectorExists('a[href="/account/family/add"]');
        $this->assertSelectorExists('form[action="/account/family/invite"] input[name="role"][value="parent"]');
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
    }
}
