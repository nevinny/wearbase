<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeOnboarding;
use App\Entity\WardrobeWearEvent;
use App\Entity\WardrobeConsent;
use App\Entity\User;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeOutfitService;

class WardrobeOutfitControllerTest extends AuthenticatedWebTestCase
{
    public function testOutfitPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseRedirects();
    }

    public function testCustomerCanOpenOutfitPage(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'AI-стилист');
        self::assertSelectorExists('form textarea[name="prompt"]');
    }

    public function testInvalidCsrfDoesNotCallLlm(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('POST', '/account/wardrobe/outfits', ['_token' => 'wrong', 'prompt' => 'В офис']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Недействительный токен');
    }

    public function testWeatherMustBeExplicitlySelected(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/account/wardrobe/outfits', ['_token' => $token, 'prompt' => 'В офис']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Выберите текущую погоду и температуру');
    }

    public function testParentCanGrantAndRevokeChildPersonalization(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'stylist-consent-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Стилист consent child');
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/outfits?member='.$child->getId());
        $form = $crawler->selectButton('Разрешить')->form();

        $client->submit($form);

        self::assertResponseRedirects('/account/wardrobe/outfits?member='.$child->getId());
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertTrue($em->getRepository(WardrobeConsent::class)->findOneBy(['subject' => $child])?->isPersonalizationGranted());

        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Отключить')->form());
        self::assertFalse($em->getRepository(WardrobeConsent::class)->findOneBy(['subject' => $child])?->isPersonalizationGranted());
    }

    public function testChildCannotGrantOwnRemoteConsent(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'stylist-consent-parent-2@test.local');
        $child = UserFactory::withEmail(static::getContainer(), 'stylist-consent-child@test.local');
        static::getContainer()->get(FamilyService::class)->acceptInvite(
            $child,
            static::getContainer()->get(FamilyService::class)->createInvite($parent, User::FAMILY_ROLE_CHILD, $child->getEmail()),
        );
        $client->loginUser($child);

        $crawler = $client->request('GET', '/account/wardrobe/outfits');

        self::assertSelectorTextContains('body', 'согласие выдаёт родитель');
        self::assertSelectorNotExists('form[action*="consent/personalization"]');
    }

    public function testValidPostRendersSuggestedOutfit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $shirt = (new WardrobeItem())->setUser($user)->setItemNo(9801)->setName('Белая рубашка')->setCategory('Рубашки');
        $trousers = (new WardrobeItem())->setUser($user)->setItemNo(9802)->setName('Синие брюки')->setCategory('Брюки');
        $em->persist($shirt);
        $em->persist($trousers);
        $em->flush();

        $mock = $this->createMock(WardrobeOutfitService::class);
        $mock->expects(self::once())
            ->method('suggest')
            ->with(
                self::callback(static fn ($actor): bool => $actor->getId() === $user->getId()),
                self::callback(static function (array $items): bool {
                    $names = array_map(static fn (WardrobeItem $item): ?string => $item->getName(), $items);

                    return in_array('Белая рубашка', $names, true) && in_array('Синие брюки', $names, true);
                }),
                'В офис',
                '',
                self::callback(static fn ($subject): bool => $subject->getId() === $user->getId()),
                '',
                'rain',
                'cold',
            )
            ->willReturn([[
                'title' => 'Спокойный офис',
                'explanation' => 'Базовые цвета сочетаются.',
                'items' => [$shirt, $trousers],
            ]]);
        static::getContainer()->set(WardrobeOutfitService::class, $mock);

        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/account/wardrobe/outfits', ['_token' => $token, 'prompt' => 'В офис', 'weather_condition' => 'rain', 'temperature_band' => 'cold']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Спокойный офис');
        self::assertSelectorTextContains('body', 'Белая рубашка');
        self::assertSelectorTextContains('body', 'Синие брюки');

        $client->submit($crawler->selectButton('❤️ Нравится')->form());
        self::assertResponseRedirects('/account/wardrobe/outfits');
        $saved = $em->getRepository(\App\Entity\WardrobeOutfit::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
        self::assertSame('like', $saved?->getReaction());
        $onboarding = $em->getRepository(WardrobeOnboarding::class)->findOneBy(['subject' => $user]);
        self::assertTrue($onboarding?->isCompleted());
    }

    public function testWornReactionCreatesOneWearEventOnRetry(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = UserFactory::withEmail(static::getContainer(), 'outfit-worn@test.local');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $next = static::getContainer()->get(\App\Repository\WardrobeItemRepository::class)->nextItemNo($user);
        $shirt = (new WardrobeItem())->setUser($user)->setItemNo($next)->setName('Футболка')->setCategory('Футболка');
        $jeans = (new WardrobeItem())->setUser($user)->setItemNo($next + 1)->setName('Джинсы')->setCategory('Джинсы');
        $em->persist($shirt);
        $em->persist($jeans);
        $em->flush();
        $client->loginUser($user);
        $mock = $this->createMock(WardrobeOutfitService::class);
        $mock->method('suggest')->willReturn([['title' => 'На каждый день', 'explanation' => '', 'items' => [$shirt, $jeans]]]);
        static::getContainer()->set(WardrobeOutfitService::class, $mock);
        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/account/wardrobe/outfits', ['_token' => $token, 'prompt' => 'На сегодня', 'weather_condition' => 'clear', 'temperature_band' => 'mild']);
        $form = $crawler->selectButton('✅ Я это надел')->form();
        $action = $form->getUri();
        $values = $form->getPhpValues();
        $client->request('POST', $action, $values);
        self::assertResponseRedirects('/account/wardrobe/outfits');
        $client->request('POST', $action, $values);
        self::assertResponseRedirects('/account/wardrobe/outfits');

        $events = $em->getRepository(WardrobeWearEvent::class)->findBy(['profileSubject' => $user, 'type' => 'worn']);
        self::assertCount(1, $events);
        self::assertCount(2, $events[0]->getItems());
    }
}
