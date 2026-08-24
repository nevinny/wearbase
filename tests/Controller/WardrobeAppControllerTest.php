<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeOnboarding;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use App\Service\Wardrobe\WardrobeOnboardingService;

class WardrobeAppControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testLoginStaysInsideStandaloneAppScope(): void
    {
        $client = static::createClient();
        $client->request('GET', '/manifest.webmanifest');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json; charset=utf-8');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/account/wardrobe-app', $manifest['start_url']);
        self::assertSame('/account/', $manifest['scope']);
        self::assertStringContainsString("scope: '/account/'", (string) file_get_contents(dirname(__DIR__, 2).'/public_html/pwa-register.js'));

        $serviceWorker = (string) file_get_contents(dirname(__DIR__, 2).'/public_html/service-worker.js');
        self::assertStringNotContainsString("'/api/", $serviceWorker);
        self::assertStringNotContainsString("'/account/", $serviceWorker);
        self::assertStringContainsString("request.mode === 'navigate'", $serviceWorker);
        self::assertStringContainsString("'Cache-Control': 'no-store'", $serviceWorker);
        self::assertStringContainsString("cacheControl.includes('private')", $serviceWorker);
        self::assertStringContainsString("cacheControl.includes('no-store')", $serviceWorker);
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
        $client->loginUser(UserFactory::withEmail(static::getContainer(), 'onboarding-dashboard-'.uniqid().'@test.local'));

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Что хотите сделать?');
        $this->assertSelectorTextContains('body', 'Мой гардероб');
        $this->assertSelectorExists('a[href="/account/wardrobe/new"]');
        $this->assertSelectorExists('a[href="/account/wardrobe/outfits"]');
        $this->assertSelectorExists('a[href="/account/wardrobe/statistics"]');
        $this->assertSelectorTextContains('body', 'Состав семьи');
        $this->assertSelectorTextContains('#wardrobe-onboarding-title', 'Добавьте первые 5 вещей');
        $this->assertSelectorNotExists('[data-testid="wear-loop-card"]');
        $this->assertSelectorExists('form[action="/account/family/invite"] input[name="role"][value="child"]');
        $this->assertSelectorExists('form[action="/account/family/invite"] input[name="role"][value="parent"]');
        $this->assertSelectorExists('#family-main.family-safe-content.pt-5:not(.py-5)');
    }

    public function testCompletedOnboardingShowsSingleWearLoopAction(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'wear-loop-dashboard-'.uniqid().'@test.local');
        static::getContainer()->get(WardrobeOnboardingService::class)->complete($user, $user);
        $client->loginUser($user);

        $client->request('GET', '/account/wardrobe-app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, '[data-testid="wear-loop-card"]');
        $this->assertSelectorExists('[data-testid="wear-loop-card"] a[href="/account/wardrobe/wear"]');
        $this->assertSelectorTextContains('[data-testid="wear-loop-card"]', 'Что на мне сегодня');
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
        $this->assertSelectorExists(sprintf('a[href="/account/wardrobe-app?member=%d"]', $child->getId()));
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

    public function testDashboardGetDoesNotPersistOnboardingAndSkipCanBeResumed(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'onboarding-skip-resume-'.uniqid().'@test.local');
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $crawler = $client->request('GET', '/account/wardrobe-app');
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $em->getRepository(WardrobeOnboarding::class)->count(['subject' => $user]));

        $form = $crawler->filter('form')->reduce(
            static fn ($node): bool => $node->filter('input[name="action"][value="skip"]')->count() > 0,
        )->form();
        $client->submit($form);
        $this->assertResponseRedirects('/account/wardrobe-app');

        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('#wardrobe-onboarding-title', 'Настроить гардероб');
        $onboarding = $em->getRepository(WardrobeOnboarding::class)->findOneBy(['subject' => $user]);
        $this->assertTrue($onboarding->isSkipped());

        $form = $crawler->filter('form')->reduce(
            static fn ($node): bool => $node->filter('input[name="action"][value="resume"]')->count() > 0,
        )->form();
        $client->submit($form);
        $this->assertResponseRedirects('/account/wardrobe-app');
        $em->refresh($onboarding);
        $this->assertFalse($onboarding->isSkipped());
    }

    public function testParentCanOpenChildOnboardingButChildCannotOpenParent(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'onboarding-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Лиза');

        $client->loginUser($parent);
        $client->request('GET', '/account/wardrobe-app?member='.$child->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#wardrobe-onboarding-title', 'Добавьте первые 5 вещей');
        $this->assertSelectorExists(sprintf('input[name="member"][value="%d"]', $child->getId()));

        $client->loginUser($child);
        $client->request('GET', '/account/wardrobe-app?member='.$parent->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testInvalidOnboardingCsrfDoesNotCreateState(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $countBefore = $em->getRepository(WardrobeOnboarding::class)->count(['subject' => $user]);

        $client->request('POST', '/account/wardrobe-app/onboarding', [
            'member' => $user->getId(),
            'action' => 'skip',
            '_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame($countBefore, $em->getRepository(WardrobeOnboarding::class)->count(['subject' => $user]));
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
