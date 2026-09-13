<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeOnboarding;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeWearEvent;
use App\Entity\WardrobeConsent;
use App\Entity\User;
use App\Repository\WardrobeOutfitRepository;
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
        // Свежий гардероб — ночная генерация ещё не считала образы; экран должен
        // объяснять это, а не выглядеть пустым/сломанным.
        self::assertSelectorTextContains('body', 'Образы ещё не готовы');
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

    public function testDailyShowcaseGroupsTodaysBatchOutfitsAndHidesSoftDeleted(): void
    {
        $client = static::createClient();
        // Отдельный пользователь (не harness-customer): daily-образы живут в БД между
        // тестами этого файла, общий customer накопил бы чужие карточки на витрине.
        $user = UserFactory::withEmail(static::getContainer(), 'daily-showcase@test.local');
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $shirt = (new WardrobeItem())->setUser($user)->setItemNo(1)->setName('Пиджак')->setCategory('Пиджаки');
        $em->persist($shirt);
        $em->flush();

        $kept = (new WardrobeOutfit())
            ->setUser($user)->setWardrobeOwner($user)->setOccasion(WardrobeOutfit::OCCASION_WORK)
            ->setTitle('Утренний деловой образ')->setItems([['id' => $shirt->getId(), 'category' => 'Пиджаки', 'color' => null, 'styles' => []]]);
        $removed = (new WardrobeOutfit())
            ->setUser($user)->setWardrobeOwner($user)->setOccasion(WardrobeOutfit::OCCASION_MEETING)
            ->setTitle('Отменённый образ на встречу')->setItems([['id' => $shirt->getId(), 'category' => 'Пиджаки', 'color' => null, 'styles' => []]]);
        $em->persist($kept);
        $em->persist($removed);
        $em->flush();

        $today = new \DateTimeImmutable('today');
        static::getContainer()->get(WardrobeOutfitRepository::class)
            ->softDeleteDailyBatch($user, WardrobeOutfit::OCCASION_MEETING, $today, $today->modify('+1 day'));
        $em->clear();

        $crawler = $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Образы на утро');
        self::assertSelectorTextContains('body', 'Утренний деловой образ');
        self::assertSelectorTextContains('body', WardrobeOutfit::DAILY_OCCASIONS[WardrobeOutfit::OCCASION_WORK]);
        self::assertSelectorTextNotContains('body', 'Отменённый образ на встречу');
        self::assertCount(0, $crawler->filter('form[action*="/'.$removed->getId().'/reaction"]'));

        // Погашенный образ недостижим и напрямую — не только скрыт из витрины.
        $token = $this->makeCsrfValid($client, 'wardrobe_outfit_reaction_'.$removed->getId());
        $client->request('POST', '/account/wardrobe/outfits/'.$removed->getId().'/reaction', ['_token' => $token, 'reaction' => WardrobeOutfit::REACTION_LIKE]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * CSRF вне активного запроса: token manager'у нужна сессия в request_stack, которой
     * после завершения request() нет. Пресетим значение в сессию клиента (как
     * LookShareControllerTest::makeCsrfValid) — во время POST isCsrfTokenValid сверит
     * submitted-токен ровно с этим значением.
     */
    private function makeCsrfValid(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $tokenId): string
    {
        $value = bin2hex(random_bytes(20));
        $session = $client->getRequest()->getSession();
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    public function testParentCanReactToChildsBatchOutfit(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'daily-outfit-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Ребёнок для батча');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())->setUser($child)->setItemNo(1)->setName('Футболка')->setCategory('Футболки');
        $em->persist($item);
        $em->flush();
        $outfit = (new WardrobeOutfit())
            ->setUser($child)->setWardrobeOwner($child)->setOccasion(WardrobeOutfit::OCCASION_WALK)
            ->setTitle('Образ ребёнка на прогулку')->setItems([['id' => $item->getId(), 'category' => 'Футболки', 'color' => null, 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();
        $outfitId = $outfit->getId();

        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/outfits?member='.$child->getId());
        self::assertSelectorTextContains('body', 'Образ ребёнка на прогулку');

        $form = $crawler->selectButton('❤️ Нравится')->form();
        $client->submit($form);

        self::assertResponseRedirects('/account/wardrobe/outfits?member='.$child->getId());
        $em->clear();
        $reacted = $em->getRepository(WardrobeOutfit::class)->find($outfitId);
        self::assertSame(WardrobeOutfit::REACTION_LIKE, $reacted?->getReaction());
    }

    public function testParentCanMarkChildsBatchOutfitAsWorn(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'daily-outfit-worn-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Ребёнок для носки');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())->setUser($child)->setItemNo(1)->setName('Куртка')->setCategory('Куртки');
        $em->persist($item);
        $em->flush();
        $outfit = (new WardrobeOutfit())
            ->setUser($child)->setWardrobeOwner($child)->setOccasion(WardrobeOutfit::OCCASION_WORK)
            ->setTitle('Образ ребёнка на работу')->setItems([['id' => $item->getId(), 'category' => 'Куртки', 'color' => null, 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();

        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/outfits?member='.$child->getId());
        $form = $crawler->selectButton('✅ Я это надел')->form();
        $client->submit($form);

        self::assertResponseRedirects('/account/wardrobe/outfits?member='.$child->getId());
        $events = $em->getRepository(WardrobeWearEvent::class)->findBy(['profileSubject' => $child, 'type' => 'worn']);
        self::assertCount(1, $events);
    }
}
