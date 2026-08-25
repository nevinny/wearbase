<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleInvite;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Repository\UserRepository;
use App\Repository\WardrobeOutfitShareRepository;
use App\Service\Circle\CircleService;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Функциональные тесты «Кружки» (docs/circles-spec.md, решения PO 2026-08-25):
 * капы 12/5, приватность несовершеннолетних, мгновенный отзыв доступа при
 * выходе/кике, кружковый грант без гостевого токена, массовый revoke детских луков.
 */
class CircleControllerTest extends DatabaseDependentWebTestCase
{
    // ── Join flow ────────────────────────────────────────────────────────────

    public function testJoinHappyPath(): void
    {
        [$client, $em, $invite] = $this->setupInvite('circle-host@test.local', 'circle-guest-happy@test.local');
        /** @var WardrobeCircleInvite $fresh */
        $fresh = $em->find(WardrobeCircleInvite::class, $invite->getId());

        $client->request('GET', '/account/circles/join/'.$fresh->getToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Школьные подруги');

        $csrf = $this->makeCsrfValid($client, 'circle_join_'.$fresh->getToken());
        $client->request('POST', '/account/circles/join/'.$fresh->getToken(), ['_token' => $csrf]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var User|null $guest */
        $guest = $em->getRepository(User::class)->findOneBy(['email' => 'circle-guest-happy@test.local']);
        /** @var WardrobeCircleMember|null $membership */
        $membership = $em->getRepository(WardrobeCircleMember::class)->findOneBy(['circle' => $fresh->getCircle(), 'user' => $guest]);
        self::assertNotNull($membership, 'Членство должно быть создано');
        self::assertSame(WardrobeCircleMember::STATUS_ACTIVE, $membership->getStatus());

        // Лента доступна новому участнику.
        $client->request('GET', '/account/circles/'.$fresh->getCircle()->getId());
        self::assertResponseIsSuccessful();
    }

    public function testExpiredInviteReturnsNeutral410(): void
    {
        [$client, $em, $invite] = $this->setupInvite('circle-host-exp@test.local', 'circle-guest-exp@test.local');

        /** @var WardrobeCircleInvite $fresh */
        $fresh = $em->find(WardrobeCircleInvite::class, $invite->getId());
        $prop = new \ReflectionProperty(WardrobeCircleInvite::class, 'expiresAt');
        $prop->setAccessible(true);
        $prop->setValue($fresh, new \DateTimeImmutable('-1 hour'));
        $em->flush();

        $client->request('GET', '/account/circles/join/'.$invite->getToken());
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testManagedChildIsDeniedJoin(): void
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        /** @var FamilyService $families */
        $families = $container->get(FamilyService::class);
        $host = UserFactory::withEmail($container, 'circle-managed-host@test.local');
        $parent = UserFactory::withEmail($container, 'circle-managed-parent@test.local');
        $circle = $this->service()->create($host, 'Детский запрет');
        $invite = $this->service()->createInvite($host, $circle);
        $managed = $families->createChild($parent, 'Малыш');

        // Акцепт под firewall'ом — жёсткий запрет для managed-профиля (§3.1): 403.

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($managed);
        $client->request('POST', '/account/circles/join/'.$invite->getToken(), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testTeenJoinStaysPendingUntilParentApproves(): void
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        [$teen, $parent] = $this->createTeenWithFamily('circles-teen-flow@test.local');
        $host = UserFactory::withEmail($container, 'circles-teen-host@test.local');
        $circle = $this->service()->create($host, 'Двор');
        $invite = $this->service()->createInvite($host, $circle);

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($teen);
        $client->request('GET', '/account/circles/join/'.$invite->getToken());
        $csrf = $this->makeCsrfValid($client, 'circle_join_'.$invite->getToken());
        $client->request('POST', '/account/circles/join/'.$invite->getToken(), ['_token' => $csrf]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var WardrobeCircleMember $pending */
        $pending = $em->getRepository(WardrobeCircleMember::class)->findOneBy(['circle' => $circle, 'user' => $teen]);
        self::assertSame(WardrobeCircleMember::STATUS_PENDING_PARENT, $pending->getStatus());

        // Без аппрува лента недоступна.
        $client->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        static::ensureKernelShutdown();

        // Родитель подтверждает членство подростка → лента открывается.
        $approver = static::createClient();
        $approver->loginUser($parent);
        $approver->request('GET', '/account/circles'); // сессия для CSRF
        $csrfApprove = $this->makeCsrfValid($approver, 'circle_member_confirm_'.$pending->getId());
        $approver->request('POST', '/account/circles/member/'.$pending->getId().'/confirm', [
            '_token' => $csrfApprove,
            'action' => 'approve',
        ]);
        self::assertResponseRedirects();
        static::ensureKernelShutdown();

        $member = static::createClient();
        $member->loginUser($teen);
        $member->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseIsSuccessful();
    }

    // ── Капы ─────────────────────────────────────────────────────────────────

    public function testMemberCapOfTwelveIsEnforcedOnJoin(): void
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        $host = UserFactory::withEmail($container, 'cap-host@test.local');
        $circle = $this->service()->create($host, 'Переполненный');
        // Владелец занял 1 слот из 12; добиваем остальные напрямую (кап проверяется на вставке).
        for ($i = 0; $i < 11; $i++) {
            $u = UserFactory::withEmail($container, sprintf('cap-member-%d@test.local', $i));
            $em->persist(new WardrobeCircleMember($circle, $u));
        }
        $em->flush();

        $thirteenth = UserFactory::withEmail($container, 'cap-thirteenth@test.local');
        $invite = $this->service()->createInvite($host, $circle);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('максимум участников');
        $this->service()->acceptInvite($thirteenth, $invite);
    }

    public function testFiveCirclesPerUserCap(): void
    {
        $container = static::getContainer();
        $this->skipIfNoDatabase();

        $creator = UserFactory::withEmail($container, 'five-cap-user@test.local');
        $service = $this->service();
        for ($i = 1; $i <= 5; $i++) {
            $service->create($creator, sprintf('Кружок %d', $i));
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не более чем в 5 кружках');
        $service->create($creator, 'Шестой лишний');
    }

    // ── Выход/кик = мгновенная потеря доступа ────────────────────────────────

    public function testLeaveRevokesFeedAccessImmediately(): void
    {
        [, $guest, $circle] = $this->createCircleWithMember('leave-host@test.local', 'leave-guest@test.local', 'Выход');

        $client = static::createClient();
        $client->loginUser($guest);
        $client->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseIsSuccessful();

        $csrf = $this->makeCsrfValid($client, 'circle_leave_'.$circle->getId());
        $client->request('POST', '/account/circles/'.$circle->getId().'/leave', ['_token' => $csrf]);
        self::assertResponseRedirects();

        $client->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testKickRevokesFeedAccessImmediately(): void
    {
        [$host, $guest, $circle, $guestMembershipId] = $this->createCircleWithMember('kick-host@test.local', 'kick-guest@test.local', 'Кик');

        $client = static::createClient();
        $client->loginUser($host);
        $client->request('GET', '/account/circles/'.$circle->getId()); // сессия для CSRF
        $csrf = $this->makeCsrfValid($client, 'circle_kick_'.$circle->getId());
        $client->request('POST', '/account/circles/'.$circle->getId().'/kick', [
            '_token' => $csrf,
            'member' => (string) $guestMembershipId,
        ]);
        self::assertResponseRedirects();

        static::ensureKernelShutdown();
        $kicked = static::createClient();
        $kicked->loginUser($guest);
        $kicked->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnerExitRequiresSuccessor(): void
    {
        [$host, $guest, $circle, $guestMembershipId] = $this->createCircleWithMember('exit-host@test.local', 'exit-guest@test.local', 'Преемник');

        // Без преемника — отказ с объяснением, владение сохраняется.
        $client = static::createClient();
        $em = $this->em();
        $client->loginUser($host);
        $client->request('GET', '/account/circles/'.$circle->getId()); // сессия для CSRF
        $csrf = $this->makeCsrfValid($client, 'circle_leave_'.$circle->getId());
        $client->request('POST', '/account/circles/'.$circle->getId().'/leave', ['_token' => $csrf]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var WardrobeCircle $fresh */
        $fresh = $em->find(WardrobeCircle::class, $circle->getId());
        self::assertSame($host->getId(), $fresh->getOwner()->getId(), 'Без преемника владелец не выходит');
        $stillMember = $em->getRepository(WardrobeCircleMember::class)->findOneBy(['circle' => $fresh, 'user' => $host]);
        self::assertSame(WardrobeCircleMember::STATUS_ACTIVE, $stillMember->getStatus());

        // С преемником — передача владения и выход.
        static::ensureKernelShutdown();
        $client2 = static::createClient();
        $client2->loginUser($host);
        $client2->request('GET', '/account/circles/'.$circle->getId()); // сессия для CSRF
        $csrf2 = $this->makeCsrfValid($client2, 'circle_leave_'.$circle->getId());
        $client2->request('POST', '/account/circles/'.$circle->getId().'/leave', [
            '_token' => $csrf2,
            'successor' => (string) $guestMembershipId,
        ]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var WardrobeCircle $transferred */
        $transferred = $em->find(WardrobeCircle::class, $circle->getId());
        self::assertSame($guest->getEmail(), $transferred->getOwner()->getEmail());
        $exHost = $em->getRepository(WardrobeCircleMember::class)->findOneBy(['circle' => $transferred, 'user' => $host]);
        self::assertSame(WardrobeCircleMember::STATUS_LEFT, $exHost->getStatus());
    }

    // ── Кружковый грант ──────────────────────────────────────────────────────

    public function testCircleShareVisibleOnlyToCircleMembersAndHasNoGuestToken(): void
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        [$host, , $circle] = $this->createCircleWithMember('share-host@test.local', 'share-member@test.local', 'Показ');

        // Хост шарит лук в кружок через UI.
        $client = static::createClient();
        $em = $this->em();
        $host = $em->find(User::class, $host->getId());
        $outfit = $this->createOutfit($host, 'Лук только для своих');
        $client->loginUser($host);
        $client->request('GET', '/account/wardrobe/outfits'); // сессия для CSRF
        $csrf = $this->makeCsrfValid($client, 'wardrobe_outfit_share_circle_'.$outfit->getId());
        $client->request('POST', '/account/wardrobe/outfits/'.$outfit->getId().'/share-circle', [
            '_token' => $csrf,
            'circle_id' => (string) $circle->getId(),
        ]);
        self::assertResponseRedirects();

        /** @var WardrobeOutfitShare|null $share */
        $share = $em->getRepository(WardrobeOutfitShare::class)->findOneBy(['outfit' => $outfit], ['id' => 'DESC']);
        self::assertNotNull($share, 'Кружковый грант должен создаться');
        self::assertSame($circle->getId(), $share->getCircle()?->getId());
        self::assertSame(WardrobeOutfitShare::STATUS_ACTIVE, $share->getStatus());
        $shareToken = $share->getToken();

        // Член кружка видит карточку в ленте.
        $client->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Лук только для своих');
        static::ensureKernelShutdown();

        // Посторонний залогиненный пользователь ленты не видит.
        $outsiderClient = static::createClient();
        $outsider = UserFactory::withEmail(static::getContainer(), 'share-outsider@test.local');
        $outsiderClient->loginUser($outsider);
        $outsiderClient->request('GET', '/account/circles/'.$circle->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Гостевая страница по токену кружкового гранта мертва: токен не выдаётся.
        static::ensureKernelShutdown();
        $guest = static::createClient();
        $guest->request('GET', '/l/'.$shareToken);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);

        // В блоке «Ссылки на образы» ЛК кружковый грант не числится.
        /** @var WardrobeOutfitShareRepository $repo */
        $repo = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeOutfitShare::class);
        self::assertSame([], $repo->findForWardrobeOwner($host));
    }

    public function testParentConsentWithdrawMassRevokesChildCircleShares(): void
    {
        $em = $this->em();
        $this->skipIfNoDatabase();

        // Родитель сам держит кружок и расшаривает туда лук подростка.
        [$teen, $parent] = $this->createTeenWithFamily('mass-revoke-teen@test.local');
        $circle = $this->service()->create($parent, 'Массовый отзыв');
        $outfit = $this->createOutfit($teen, 'Детский лук в кружке');
        $this->service()->shareToCircle($parent, $outfit, $circle);

        /** @var WardrobeOutfitShare $first */
        $first = $em->getRepository(WardrobeOutfitShare::class)->findOneBy(['outfit' => $outfit], ['id' => 'DESC']);
        self::assertSame(WardrobeOutfitShare::STATUS_ACTIVE, $first->getStatus());
        self::assertNotNull($first->getCircle());
        static::ensureKernelShutdown(); // сервис-сетап грузил ядро напрямую

        // Отзыв родительского согласия → массовый revoke кружковых грантов детских луков.
        $client = static::createClient();
        $client->loginUser($parent);
        $client->request('GET', '/account/circles'); // сессия для CSRF
        $csrf = $this->makeCsrfValid($client, 'circle_child_shares_'.$teen->getId());
        $client->request('POST', '/account/circles/consent/'.$teen->getId().'/revoke-shares', ['_token' => $csrf]);
        self::assertResponseRedirects();

        $em->clear();
        /** @var WardrobeOutfitShare $revoked */
        $revoked = $em->find(WardrobeOutfitShare::class, $first->getId());
        self::assertSame(WardrobeOutfitShare::STATUS_REVOKED, $revoked->getStatus());

        // И лента опустела: предикат отдаёт только живые гранты.
        static::ensureKernelShutdown();
        $watcher = static::createClient();
        $watcher->loginUser($parent);
        $watcher->request('GET', '/account/circles/'.$circle->getId());
        self::assertSelectorTextContains('body', 'Пока никто ничего не показал');
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function service(): CircleService
    {
        return static::getContainer()->get(CircleService::class);
    }

    /**
     * Хост создаёт кружок, гость вступает по инвайту (через сервис).
     *
     * @return array{0: User, 1: User, 2: WardrobeCircle, 3: int} хост, гость, кружок, id членства гостя
     */
    private function createCircleWithMember(string $hostEmail, string $guestEmail, string $title): array
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        $host = UserFactory::withEmail($container, $hostEmail);
        $guest = UserFactory::withEmail($container, $guestEmail);
        $circle = $this->service()->create($host, $title);
        $invite = $this->service()->createInvite($host, $circle);
        $membership = $this->service()->acceptInvite($guest, $invite);
        $membershipId = (int) $membership->getId();
        $em->clear();
        static::ensureKernelShutdown(); // setup загрузил ядро напрямую — гасим до createClient()

        return [$host, $guest, $circle, $membershipId];
    }

    /** Хост + кружок «Школьные подруги» + свежий инвайт; клиент залогинен под гостем. */
    private function setupInvite(string $hostEmail, string $guestEmail): array
    {
        $container = static::getContainer();
        $this->skipIfNoDatabase();

        $host = UserFactory::withEmail($container, $hostEmail);
        UserFactory::withEmail($container, $guestEmail);
        $circle = $this->service()->create($host, 'Школьные подруги');
        $invite = $this->service()->createInvite($host, $circle);

        static::ensureKernelShutdown();
        $client = static::createClient();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $client->loginUser($users->findOneBy(['email' => $guestEmail]));

        return [$client, static::getContainer()->get('doctrine.orm.entity_manager'), $invite];
    }

    /** Семья: родитель + подросток с личным входом (familyRole=child, не managed). */
    private function createTeenWithFamily(string $teenEmail): array
    {
        $container = static::getContainer();
        $em = $this->em();
        /** @var FamilyService $families */
        $families = $container->get(FamilyService::class);

        $parent = UserFactory::withEmail($container, 'circles-parent@test.local');
        $teen = UserFactory::withEmail($container, $teenEmail);
        $invite = $families->createInvite($parent, User::FAMILY_ROLE_CHILD);
        $families->acceptInvite($teen, $invite);
        $teen->setBirthDate(new \DateTimeImmutable('-13 years'));
        $em->flush();

        return [$teen, $parent];
    }

    private function createOutfit(User $owner, string $title): WardrobeOutfit
    {
        $em = $this->em();
        // Хелпер может получить пользователя из предыдущего ядра — перецепляемся к текущему em.
        $owner = $em->find(User::class, $owner->getId()) ?? $owner;
        $shirt = (new WardrobeItem())->setUser($owner)->setItemNo(random_int(10000, 999999))->setName('Белая рубашка')->setCategory('Рубашки');
        $em->persist($shirt);
        $em->flush();

        $outfit = (new WardrobeOutfit())
            ->setUser($owner)
            ->setWardrobeOwner($owner)
            ->setTitle($title)
            ->setItems([['id' => $shirt->getId(), 'category' => 'Рубашки', 'color' => 'белый', 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();

        return $outfit;
    }

    /**
     * CSRF вне активного запроса: пресетим значение в сессию клиента (паттерн
     * LookShareControllerTest::makeCsrfValid).
     */
    private function makeCsrfValid(KernelBrowser $client, string $tokenId): string
    {
        $value = bin2hex(random_bytes(20));
        $session = $client->getRequest()->getSession();
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }
}
