<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ReferralEvent;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Repository\ReferralEventRepository;
use App\Service\FamilyService;
use App\Service\Look\LookShareReferralService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Функциональные тесты «Поделиться луком» (_docs/outfit-sharing-spec.md §1–7,
 * решения PO 2026-08-24): гостевой /l/{token}, приватность, parent-confirm, referral_event.
 */
class LookShareControllerTest extends DatabaseDependentWebTestCase
{
    public function testGuestViewHappyPath(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$owner, $outfit] = $this->createOutfitWithOwner('look-owner-happy@test.local', 'Осенний образ для прогулки');
        $share = $this->createActiveShare($owner, $outfit);
        $token = $share->getToken();

        $crawler = $client->request('GET', '/l/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Осенний образ для прогулки');

        // Заголовки приватности: noindex,follow + no-store + no-referrer (§5, §4.3).
        self::assertSame('noindex, follow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));

        // Self-canonical абсолютный, без ?ref= и прочих параметров.
        $canonical = (string) $crawler->filter('link[rel="canonical"]')->attr('href');
        self::assertMatchesRegularExpression('#/l/'.preg_quote($token, '#').'$#', $canonical);

        // OG-теги (§3.1): absolute og:image 1200x630, og:url без query.
        self::assertSame('Лук: Осенний образ для прогулки | WEARBASE', $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertMatchesRegularExpression('#/l/'.preg_quote($token, '#').'/og\.png$#', (string) $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('1200', $crawler->filter('meta[property="og:image:width"]')->attr('content'));
        self::assertSame('630', $crawler->filter('meta[property="og:image:height"]')->attr('content'));
        self::assertSame($canonical, $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));

        // CTA регистрации несёт ref+target, UTM нет по построению.
        $cta = (string) $crawler->filter('a[href*="/register"]')->attr('href');
        self::assertStringContainsString('ref='.$token, $cta);
        self::assertStringContainsString('target=', $cta);

        // Внутренний счётчик §6: реальный гость инкрементит ровно на 1.
        $em->clear();
        /** @var WardrobeOutfitShare $fresh */
        $fresh = $em->find(WardrobeOutfitShare::class, $share->getId());
        self::assertSame(1, $fresh->getViewCount());
        self::assertNotNull($fresh->getLastViewedAt());
    }

    public function testBotUserAgentDoesNotIncrementViewCounter(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$owner, $outfit] = $this->createOutfitWithOwner('look-owner-bot@test.local', 'Бот-превью');
        $share = $this->createActiveShare($owner, $outfit);

        $client->request('GET', '/l/'.$share->getToken(), [], [], ['HTTP_USER_AGENT' => 'TelegramBot (like TwitterBot)']);

        self::assertResponseIsSuccessful();
        $em->clear();
        /** @var WardrobeOutfitShare $fresh */
        $fresh = $em->find(WardrobeOutfitShare::class, $share->getId());
        self::assertSame(0, $fresh->getViewCount(), 'Превью бота не должно считаться просмотром');
    }

    public function testExpiredShareReturnsNeutral410(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$owner, $outfit] = $this->createOutfitWithOwner('look-owner-expired@test.local', 'Истёкший образ');
        $share = new WardrobeOutfitShare($outfit, $owner, WardrobeOutfitShare::TTL_24H);
        $share->approve();
        // Просрочка: expires_at в прошлом через reflection (сеттер TTL всегда «вперёд»).
        $prop = new \ReflectionProperty(WardrobeOutfitShare::class, 'expiresAt');
        $prop->setAccessible(true);
        $prop->setValue($share, new \DateTimeImmutable('-1 hour'));
        $em->persist($share);
        $em->flush();

        $client->request('GET', '/l/'.$share->getToken());

        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, follow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorTextContains('body', 'Ссылка больше не действует');
    }

    public function testRevokedShareReturns410(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$owner, $outfit] = $this->createOutfitWithOwner('look-owner-revoked@test.local', 'Отозванный образ');
        $share = $this->createActiveShare($owner, $outfit);
        $share->revoke();
        $em->flush();

        $client->request('GET', '/l/'.$share->getToken());

        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testPendingParentShareHiddenFromGuests(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        [$teen, $outfit] = $this->createTeenWithFamilyAndOutfit('look-teen-pending@test.local');
        // Подросток сам создаёт ссылку: pending_parent до аппрува родителя (PO №3).
        $client->loginUser($teen);
        $share = $this->createShareViaUi($client, $outfit);
        self::assertTrue($share->isPendingParent());
        self::assertNull($share->getGrantedAt());

        static::ensureKernelShutdown(); // второй клиент: ядро должно быть погашено
        $guest = static::createClient();
        $guest->request('GET', '/l/'.$share->getToken());
        self::assertResponseStatusCodeSame(410);
    }

    public function testMediaEndpointRejectsPhotoOutsideTheShare(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$owner, $outfit] = $this->createOutfitWithOwner('look-owner-media@test.local', 'Образ с фото');
        $share = $this->createActiveShare($owner, $outfit);

        // Чужая вещь того же владельца, НЕ входящая в снапшот лука.
        $foreignItem = (new WardrobeItem())->setUser($owner)->setItemNo(random_int(100000, 999999))->setName('Чужая куртка')->setCategory('Куртки');
        $em->persist($foreignItem);
        $em->flush();
        $foreignPhoto = new \App\Entity\WardrobeItemPhoto();
        $foreignPhoto->setItem($foreignItem);
        $foreignPhoto->setFilePath('aa/bb/foreign.jpg');
        $em->persist($foreignPhoto);
        $em->flush();

        // Голый photoId вне share → 404 даже с валидным shareToken (чек-лист утечек §4.3).
        $client->request('GET', '/l/media/'.$share->getToken().'/'.$foreignPhoto->getId());
        self::assertResponseStatusCodeSame(404);

        // Мусорный токен → 404.
        $client->request('GET', '/l/media/'.str_repeat('f', 64).'/'.$foreignPhoto->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testReferralEventWrittenExactlyOnceOnRegisterWithRef(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$sharer, $outfit] = $this->createOutfitWithOwner('look-sharer-ref@test.local', 'Пригласительный образ');
        $share = $this->createActiveShare($sharer, $outfit);
        $token = $share->getToken();

        // Гость открывает лук с ?ref={shareToken} — связка уходит в сессию (§7).
        $client->request('GET', '/l/'.$token.'?ref='.$token);
        self::assertResponseIsSuccessful();

        $email = 'look-guest-'.uniqid().'@example.com';
        $crawler = $client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/register', [
            'registration_form' => [
                'firstName' => 'Гость',
                'email' => $email,
                'plainPassword' => ['first' => 'Passw0rd!123', 'second' => 'Passw0rd!123'],
                'agreeTerms' => '1',
                '_token' => (string) $crawler->filter('input[name="registration_form[_token]"]')->attr('value'),
            ],
            // Turnstile: dummy-ключи always-pass (.env.test), JS-виджет в тесте шлём вручную.
            'cf-turnstile-response' => 'dummy',
            // Скрытое поле CTA (страховка при потере сессии).
            'target' => '/l/'.$token,
        ]);

        self::assertResponseRedirects('/l/'.$token, null, 'После регистрации гость возвращается на лук');

        /** @var User|null $invitee */
        $invitee = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($invitee);

        /** @var list<ReferralEvent> $events */
        $events = $em->getRepository(ReferralEvent::class)->findBy(['invitee' => $invitee]);
        self::assertCount(1, $events, 'Ровно одно referral-событие на регистрацию с ref');
        self::assertSame(ReferralEvent::SOURCE_LOOK_SHARE, $events[0]->getSource());
        self::assertSame($sharer->getId(), $events[0]->getInviter()->getId());
        self::assertSame($share->getId(), $events[0]->getShareId());

        // Повторный резолв того же ref (например, логин по ссылке) не плодит события.
        $service = static::getContainer()->get(LookShareReferralService::class);
        $session = $client->getRequest()->getSession();
        $session->set(LookShareReferralService::SESSION_KEY, $token);
        $request = Request::create('/login');
        $request->setSession($session);
        $service->recordFromSession($request, $invitee);

        self::assertCount(
            1,
            $em->getRepository(ReferralEvent::class)->findBy(['invitee' => $invitee]),
            'Повторный резолв ref не должен создавать второе событие',
        );
    }

    public function testChildInitiatedShareStaysPendingUntilParentApproves(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $em = $this->em();

        [$teen, $outfit] = $this->createTeenWithFamilyAndOutfit('look-teen-flow@test.local');

        // Подросток создаёт ссылку — она не открывает гостям страницу до аппрува родителя.
        $client->loginUser($teen);
        $share = $this->createShareViaUi($client, $outfit);
        self::assertSame(WardrobeOutfitShare::STATUS_PENDING_PARENT, $share->getStatus());
        self::assertNull($share->getGrantedAt());

        // Родитель подтверждает: статус active, TTL стартует с аппрува.
        $parent = $em->getRepository(User::class)->findOneBy(['email' => 'look-parent@test.local']);
        self::assertNotNull($parent, 'Родитель должен существовать в сценарии семьи');
        static::ensureKernelShutdown(); // второй клиент: ядро должно быть погашено
        $approver = static::createClient();
        $approver->loginUser($parent);
        // Сессия: GET страницы ЛК до пресета CSRF-значения.
        $approver->request('GET', '/account/wardrobe/outfits');
        $csrf = $this->makeCsrfValid($approver, 'wardrobe_outfit_share_confirm_'.$share->getId());
        $approver->request('POST', '/account/wardrobe/outfits/share/'.$share->getId().'/confirm', [
            '_token' => $csrf,
            'action' => 'approve',
        ]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var WardrobeOutfitShare $approved */
        $approved = $em->find(WardrobeOutfitShare::class, $share->getId());
        self::assertSame(WardrobeOutfitShare::STATUS_ACTIVE, $approved->getStatus());
        self::assertNotNull($approved->getGrantedAt());

        // Теперь гость видит страницу.
        static::ensureKernelShutdown(); // третий клиент: ядро должно быть погашено
        $guestClient = static::createClient();
        $guestClient->request('GET', '/l/'.$approved->getToken());
        self::assertResponseIsSuccessful();

        // Отзыв мгновенно закрывает доступ (410).
        static::ensureKernelShutdown(); // четвёртый клиент: ядро должно быть погашено
        $revoker = static::createClient();
        $revoker->loginUser($parent);
        $revoker->request('GET', '/account/wardrobe/outfits'); // сессия для CSRF-токена
        $csrfRevoke = $this->makeCsrfValid($revoker, 'wardrobe_outfit_share_revoke_'.$share->getId());
        $revoker->request('POST', '/account/wardrobe/outfits/share/'.$share->getId().'/revoke', ['_token' => $csrfRevoke]);
        self::assertResponseRedirects();

        $em->clear();
        static::ensureKernelShutdown(); // ядро погашено после POST ревока
        $guestAfterRevoke = static::createClient(); // свежий клиент поверх нового ядра
        $guestAfterRevoke->request('GET', '/l/'.$approved->getToken());
        self::assertResponseStatusCodeSame(410);
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /** @return array{0: User, 1: WardrobeOutfit} */
    private function createOutfitWithOwner(string $email, string $title): array
    {
        $em = $this->em();
        $owner = UserFactory::withEmail(static::getContainer(), $email);
        $shirt = (new WardrobeItem())->setUser($owner)->setItemNo(random_int(10000, 999999))->setName('Белая рубашка')->setCategory('Рубашки');
        $trousers = (new WardrobeItem())->setUser($owner)->setItemNo(random_int(10000, 999999))->setName('Синие брюки')->setCategory('Брюки');
        $em->persist($shirt);
        $em->persist($trousers);
        $em->flush(); // сначала вещи: нужны id для снапшота

        $outfit = (new WardrobeOutfit())
            ->setUser($owner)
            ->setWardrobeOwner($owner)
            ->setTitle($title)
            ->setExplanation('Базовые цвета сочетаются.')
            ->setItems([
                ['id' => $shirt->getId(), 'category' => 'Рубашки', 'color' => 'белый', 'styles' => []],
                ['id' => $trousers->getId(), 'category' => 'Брюки', 'color' => 'синий', 'styles' => []],
            ]);
        $em->persist($outfit);
        $em->flush();

        return [$owner, $outfit];
    }

    private function createActiveShare(User $creator, WardrobeOutfit $outfit): WardrobeOutfitShare
    {
        $share = new WardrobeOutfitShare($outfit, $creator, WardrobeOutfitShare::TTL_7D);
        $share->approve();
        $this->em()->persist($share);
        $this->em()->flush();

        return $share;
    }

    /**
     * Семья: родитель look-parent@test.local (get-or-create) + подросток с личным входом
     * (familyRole=child, не managed). Возвращает подростка и его лук.
     *
     * @return array{0: User, 1: WardrobeOutfit}
     */
    private function createTeenWithFamilyAndOutfit(string $teenEmail): array
    {
        $container = static::getContainer();
        $em = $this->em();
        /** @var FamilyService $families */
        $families = $container->get(FamilyService::class);

        $parent = $em->getRepository(User::class)->findOneBy(['email' => 'look-parent@test.local'])
            ?? UserFactory::withEmail($container, 'look-parent@test.local');
        $teen = UserFactory::withEmail($container, $teenEmail);

        $invite = $families->createInvite($parent, User::FAMILY_ROLE_CHILD);
        $families->acceptInvite($teen, $invite);
        $teen->setBirthDate(new \DateTimeImmutable('-13 years'));
        $em->flush();

        $item = (new WardrobeItem())->setUser($teen)->setItemNo(random_int(10000, 999999))->setName('Худи')->setCategory('Худи');
        $em->persist($item);
        $em->flush();

        $outfit = (new WardrobeOutfit())
            ->setUser($teen)
            ->setWardrobeOwner($teen)
            ->setTitle('Школьный образ')
            ->setItems([['id' => $item->getId(), 'category' => 'Худи', 'color' => 'серый', 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();

        return [$teen, $outfit];
    }

    /**
     * CSRF вне активного запроса: token manager'у нужна сессия в request_stack, которой
     * после завершения request() нет. Пресетим значение в сессию клиента и сохраняем
     * хранилище (mock_file пишет только на save()) — во время POST isCsrfTokenValid
     * сверит submitted-токен ровно с этим значением.
     */
    private function makeCsrfValid(KernelBrowser $client, string $tokenId): string
    {
        $value = bin2hex(random_bytes(20));
        $session = $client->getRequest()->getSession();
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    /** POST создания ссылки через UI-маршрут; возвращает свежую строку share. */
    private function createShareViaUi(KernelBrowser $client, WardrobeOutfit $outfit): WardrobeOutfitShare
    {
        // Сессия для CSRF: сначала GET страницы ЛК, чтобы в request_stack появилась сессия.
        $client->request('GET', '/account/wardrobe/outfits');
        $csrf = $this->makeCsrfValid($client, 'wardrobe_outfit_share_'.$outfit->getId());
        $client->request('POST', '/account/wardrobe/outfits/'.$outfit->getId().'/share', [
            '_token' => $csrf,
            'ttl' => WardrobeOutfitShare::TTL_7D,
        ]);
        self::assertResponseRedirects();

        /** @var WardrobeOutfitShare|null $share */
        $share = $this->em()
            ->getRepository(WardrobeOutfitShare::class)
            ->findOneBy(['outfit' => $outfit], ['id' => 'DESC']);
        self::assertNotNull($share, 'Ссылка должна создаваться POST-ом из ЛК');

        return $share;
    }
}
