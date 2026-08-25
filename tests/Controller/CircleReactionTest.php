<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Entity\WardrobeShareReaction;
use App\Service\Circle\CircleReactionService;
use App\Service\Circle\CircleService;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Функциональные тесты «Огней» (docs/ratings-spec.md, решения PO 2026-08-25):
 * запрет самореакции по обоим путям авторства, только active-члены, идемпотентный
 * повтор и гонка uniq → 200, нейтральный 404 чужого кружка, каскад при удалении
 * share, positive-only (эндпоинта дизлайка нет), лимитер circle_reaction 60/день.
 */
class CircleReactionTest extends DatabaseDependentWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    // ── Хеппи-пас + идемпотентность + UI ─────────────────────────────────────

    public function testMemberReactsIdempotentlyAndFeedShowsFireButton(): void
    {
        [$host, $guest, $circle, $share] = $this->setupCircleWithSharedOutfit('react-host@test.local', 'react-guest@test.local', 'Идемпотентность');
        $circleId = (int) $circle->getId();
        $shareId = (int) $share->getId();

        $guestClient = static::createClient();
        $guestClient->loginUser($guest);

        // Первый клик: 200 + reacted + count=1.
        $guestClient->request('POST', $this->reactUrl($circleId, $shareId), ['_token' => $this->csrf($guestClient, $circleId, $shareId)]);
        self::assertResponseIsSuccessful();
        /** @var array{ok: bool, reacted: bool, count: int} $data */
        $data = json_decode((string) $guestClient->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertTrue($data['reacted']);
        self::assertSame(1, $data['count']);

        // Повторный POST — тот же ответ, второй строки в БД нет (идемпотентность §3.4).
        // Сервис без fast-path: вторая вставка бьётся об uniq_share_member_reaction
        // и гасится catch UniqueConstraintViolationException → всё те же 200/1.
        $guestClient->request('POST', $this->reactUrl($circleId, $shareId), ['_token' => $this->csrf($guestClient, $circleId, $shareId)]);
        self::assertResponseIsSuccessful();
        /** @var array{ok: bool, count: int} $repeat */
        $repeat = json_decode((string) $guestClient->getResponse()->getContent(), true);
        self::assertTrue($repeat['ok']);
        self::assertSame(1, $repeat['count']);
        self::assertSame(1, $this->reactionCount($shareId));

        // Лента: гость видит кнопку с суммой; автор — сумму без кнопки.
        static::ensureKernelShutdown();
        $watcher = static::createClient();
        $watcher->loginUser($guest);
        $watcher->request('GET', '/account/circles/'.$circleId);
        self::assertSelectorExists('button.js-fire');
        self::assertSelectorTextContains('.js-fire-count', '1');
        static::ensureKernelShutdown();

        $authorView = static::createClient();
        $authorView->loginUser($host);
        $authorView->request('GET', '/account/circles/'.$circleId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button.js-fire');
        self::assertSelectorTextContains('body', '🔥 1');
    }

    public function testAuthorBlockShowsSumAndBadgeToAuthorOnly(): void
    {
        [$host, , $circle, $share] = $this->setupCircleWithSharedOutfit('fires-author@test.local', 'fires-guest@test.local', 'Огни автора');
        $shareId = (int) $share->getId();

        // Пять огней от пяти участников — ровно порог бейджа «5» (§3.3).
        $em = $this->em();
        $managedHost = $em->find(User::class, (int) $host->getId());
        $managedCircle = $em->find(WardrobeCircle::class, (int) $circle->getId());
        self::assertNotNull($managedHost);
        self::assertNotNull($managedCircle);
        for ($i = 0; $i < 5; $i++) {
            $member = UserFactory::withEmail(static::getContainer(), sprintf('fires-voter-%d@test.local', $i));
            $invite = $this->service()->createInvite($managedHost, $managedCircle);
            $membership = $this->service()->acceptInvite($member, $invite);
            $em->persist(new WardrobeShareReaction(
                $em->find(WardrobeOutfitShare::class, $shareId),
                $em->find(WardrobeCircleMember::class, $membership->getId()),
            ));
            $em->flush();
        }
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->loginUser(UserFactory::withEmail(static::getContainer(), 'fires-author@test.local'));
        $client->request('GET', '/account/circles');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Огни на твои луки');
        self::assertSelectorTextContains('body', 'бейдж 5');

        // Гость — не автор ни одного лука: блок не показывается вовсе.
        static::ensureKernelShutdown();
        $guestClient = static::createClient();
        $guestClient->loginUser(UserFactory::withEmail(static::getContainer(), 'fires-guest@test.local'));
        $guestClient->request('GET', '/account/circles');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Огни на твои луки');
    }

    // ── Антиабьюз §4 ─────────────────────────────────────────────────────────

    public function testSelfFeedbackDeniedOnBothAuthorshipPaths(): void
    {
        // Путь 1: outfit.user === actor (хост реагирует на свой лук).
        [$host, , $circle, $share] = $this->setupCircleWithSharedOutfit('self-owner@test.local', 'self-guest@test.local', 'Своё нельзя');
        $client = static::createClient();
        $client->loginUser($host);
        $client->request('POST', $this->reactUrl((int) $circle->getId(), (int) $share->getId()), [
            '_token' => $this->csrf($client, (int) $circle->getId(), (int) $share->getId()),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->reactionCount((int) $share->getId()));
        static::ensureKernelShutdown();

        // Путь 2: createdBy === actor (родитель расшарил лук подростка и «реагирует» сам).
        $container = static::getContainer();
        $circleOwner = UserFactory::withEmail($container, 'self-createdby-circle-owner@test.local');
        $parent = UserFactory::withEmail($container, 'self-createdby-parent@test.local');
        $secondCircle = $this->service()->create($circleOwner, 'Кружок родителя');
        $invite = $this->service()->createInvite($circleOwner, $secondCircle);
        $this->service()->acceptInvite($parent, $invite); // родитель — active-член чужого кружка

        [$teen, $familyParent] = $this->createTeenWithFamily('self-createdby-teen@test.local');
        $teenOutfit = $this->createOutfit($teen, 'Лук подростка');
        // Семейный родитель тоже должен быть active-членом кружка, чтобы шарить.
        $familyInvite = $this->service()->createInvite($circleOwner, $secondCircle);
        $this->service()->acceptInvite($familyParent, $familyInvite);
        $teenShare = $this->service()->shareToCircle($familyParent, $teenOutfit, $secondCircle);
        static::ensureKernelShutdown();

        $parentClient = static::createClient();
        $parentClient->loginUser($familyParent);
        $parentClient->request('POST', $this->reactUrl((int) $secondCircle->getId(), (int) $teenShare->getId()), [
            '_token' => $this->csrf($parentClient, (int) $secondCircle->getId(), (int) $teenShare->getId()),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->reactionCount((int) $teenShare->getId()));
    }

    public function testNonActiveMembersAreRejected(): void
    {
        // pending_parent: подросток вступил, но родитель ещё не подтвердил.
        $container = static::getContainer();
        $em = $this->em();
        $pendingHost = UserFactory::withEmail($container, 'pending-react-host@test.local');
        $pendingCircle = $this->service()->create($pendingHost, 'Пендинг');
        $outfitPending = $this->createOutfit($pendingHost, 'Лук в пендинг-кружке');
        $sharePending = $this->service()->shareToCircle($pendingHost, $outfitPending, $pendingCircle);
        [$teen] = $this->createTeenWithFamily('pending-react-teen@test.local');
        $teenInvite = $this->service()->createInvite($pendingHost, $pendingCircle);
        $teenMembership = $this->service()->acceptInvite($teen, $teenInvite);
        self::assertSame(WardrobeCircleMember::STATUS_PENDING_PARENT, $teenMembership->getStatus());
        $pendingIds = [(int) $pendingCircle->getId(), (int) $sharePending->getId()];
        $em->clear();
        static::ensureKernelShutdown();

        $teenClient = static::createClient();
        $teenClient->loginUser(UserFactory::withEmail(static::getContainer(), 'pending-react-teen@test.local'));
        $teenClient->request('POST', $this->reactUrl(...$pendingIds), ['_token' => $this->csrf($teenClient, ...$pendingIds)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::ensureKernelShutdown();

        // left: участник вышел — реакция отклонена.
        [, $leaveGuest, $leaveCircle, ] = $this->setupCircleWithSharedOutfit('left-host@test.local', 'left-guest@test.local', 'Вышел');
        $leaveIds = [(int) $leaveCircle->getId(), 0];
        $em = $this->em();
        /** @var WardrobeOutfitShare|null $leaveShare */
        $leaveShare = $em->getRepository(WardrobeOutfitShare::class)->findOneBy(['circle' => $leaveIds[0]]);
        self::assertNotNull($leaveShare);
        $leaveIds[1] = (int) $leaveShare->getId();
        $this->service()->leave(
            $em->find(User::class, (int) $leaveGuest->getId()),
            $em->find(WardrobeCircle::class, $leaveIds[0]),
        );
        static::ensureKernelShutdown();

        $leftClient = static::createClient();
        $leftClient->loginUser(UserFactory::withEmail(static::getContainer(), 'left-guest@test.local'));
        $leftClient->request('POST', $this->reactUrl(...$leaveIds), ['_token' => $this->csrf($leftClient, ...$leaveIds)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::ensureKernelShutdown();

        // kicked: исключённого тоже больше нет (кик через сервис под хостом).
        [$kickHost, , $kickCircle] = $this->setupCircleWithSharedOutfit('kick-react-host@test.local', 'kick-react-guest@test.local', 'Кик реакции');
        $kickIds = [(int) $kickCircle->getId(), 0];
        $em = $this->em();
        /** @var WardrobeOutfitShare|null $kickShare */
        $kickShare = $em->getRepository(WardrobeOutfitShare::class)->findOneBy(['circle' => $kickIds[0]]);
        self::assertNotNull($kickShare);
        $kickIds[1] = (int) $kickShare->getId();
        /** @var WardrobeCircleMember|null $kickMembership */
        $kickMembership = $em->getRepository(WardrobeCircleMember::class)->findOneBy([
            'circle' => $em->find(WardrobeCircle::class, $kickIds[0]),
            'user' => $em->getRepository(User::class)->findOneBy(['email' => 'kick-react-guest@test.local']),
        ]);
        self::assertNotNull($kickMembership);
        $this->service()->kick(
            $em->find(User::class, (int) $kickHost->getId()),
            $em->find(WardrobeCircle::class, $kickIds[0]),
            (int) $kickMembership->getId(),
        );
        static::ensureKernelShutdown();

        $kickedClient = static::createClient();
        $kickedClient->loginUser(UserFactory::withEmail(static::getContainer(), 'kick-react-guest@test.local'));
        $kickedClient->request('POST', $this->reactUrl(...$kickIds), ['_token' => $this->csrf($kickedClient, ...$kickIds)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::ensureKernelShutdown();

        // Посторонний (не член кружка вообще) — отказ без деталей.
        $outsider = UserFactory::withEmail(static::getContainer(), 'react-outsider@test.local');
        static::ensureKernelShutdown();
        $outsiderClient = static::createClient();
        $outsiderClient->loginUser($outsider);
        $outsiderClient->request('POST', $this->reactUrl(...$kickIds), ['_token' => $this->csrf($outsiderClient, ...$kickIds)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testForeignCircleReturnsNeutral404(): void
    {
        [, , $circleA, $shareA] = $this->setupCircleWithSharedOutfit('foreign-a@test.local', 'foreign-a-guest@test.local', 'Кружок А');
        [, $guestB, $circleB] = $this->setupCircleWithSharedOutfit('foreign-b@test.local', 'foreign-b-guest@test.local', 'Кружок Б');

        // Член кружка Б пытается отреагировать по URL кружка Б на share из кружка А.
        $client = static::createClient();
        $client->loginUser($guestB);
        $url = '/account/circles/'.$circleB->getId().'/shares/'.$shareA->getId().'/react';
        $client->request('POST', $url, ['_token' => $this->csrf($client, (int) $circleB->getId(), (int) $shareA->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->reactionCount((int) $shareA->getId()));
    }

    public function testRevokedShareIsDeadButAuthorKeepsTheSum(): void
    {
        [$host, $guest, $circle, $share] = $this->setupCircleWithSharedOutfit('revoke-host@test.local', 'revoke-guest@test.local', 'Отзыв');
        $circleId = (int) $circle->getId();
        $shareId = (int) $share->getId();

        $guestClient = static::createClient();
        $guestClient->loginUser($guest);
        $guestClient->request('POST', $this->reactUrl($circleId, $shareId), ['_token' => $this->csrf($guestClient, $circleId, $shareId)]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->reactionCount($shareId));
        static::ensureKernelShutdown();

        // Отзыв гранта: реакция по мёртвому share больше не принимается (404).
        $em = $this->em();
        $em->find(WardrobeOutfitShare::class, $shareId)->revoke();
        $em->flush();
        static::ensureKernelShutdown();

        $afterRevoke = static::createClient();
        $afterRevoke->loginUser($guest);
        $afterRevoke->request('POST', $this->reactUrl($circleId, $shareId), ['_token' => $this->csrf($afterRevoke, $circleId, $shareId)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        static::ensureKernelShutdown();

        // …но сумма автору остаётся видимой в блоке ЛК (решение PO №5).
        $authorClient = static::createClient();
        $authorClient->loginUser($host);
        $authorClient->request('GET', '/account/circles');
        self::assertSelectorTextContains('body', '(публикация скрыта)');
        self::assertSelectorTextContains('body', '🔥 1');
        static::ensureKernelShutdown();

        // Жёсткое удаление строки share уносит реакции каскадом (FK ON DELETE CASCADE).
        $em2 = $this->em();
        $em2->getConnection()->executeStatement('PRAGMA foreign_keys=ON');
        $em2->remove($em2->find(WardrobeOutfitShare::class, $shareId));
        $em2->flush();
        self::assertSame(0, $this->reactionCount($shareId));
    }

    public function testPositiveOnlyNoDislikeEndpoint(): void
    {
        [, $guest, $circle, $share] = $this->setupCircleWithSharedOutfit('positive-host@test.local', 'positive-guest@test.local', 'Только позитив');

        $client = static::createClient();
        $client->loginUser($guest);
        $client->request('POST', '/account/circles/'.$circle->getId().'/shares/'.$share->getId().'/dislike', [
            '_token' => $this->csrf($client, (int) $circle->getId(), (int) $share->getId()),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->reactionCount((int) $share->getId()));
    }

    public function testRateLimiterContractIsSixtyPerDayAndDisabledInTests(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/rate_limiter.yaml');

        $limiter = $config['framework']['rate_limiter']['circle_reaction'] ?? null;
        self::assertNotNull($limiter, 'Лимитер circle_reaction должен быть объявлен');
        self::assertSame('sliding_window', $limiter['policy']);
        self::assertSame(60, $limiter['limit']);
        self::assertSame('1 day', $limiter['interval']);

        // В тестовом окружении лимитер отключён (no_limit), иначе сьют флейкал бы 429.
        $testOverride = $config['when@test']['framework']['rate_limiter']['circle_reaction'] ?? null;
        self::assertNotNull($testOverride, 'when@test должен отключать circle_reaction');
        self::assertSame('no_limit', $testOverride['policy']);

        // Сервис реально зарегистрирован в контейнере — лимитер не мёртвый конфиг.
        self::assertInstanceOf(CircleReactionService::class, static::getContainer()->get(CircleReactionService::class));
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

    private function reactionCount(int $shareId): int
    {
        return (int) $this->em()->getRepository(WardrobeShareReaction::class)->countForShare($shareId);
    }

    private function reactUrl(int $circleId, int $shareId): string
    {
        return '/account/circles/'.$circleId.'/shares/'.$shareId.'/react';
    }

    /**
     * CSRF: GET к ЛК открывает сессию, затем значение пресетится в неё
     * (паттерн CircleControllerTest::makeCsrfValid).
     */
    private function csrf(KernelBrowser $client, int $circleId, int $shareId): string
    {
        $client->request('GET', '/account/circles'); // инициализация сессии
        $tokenId = 'circle_react_'.$circleId.'_'.$shareId;
        $value = bin2hex(random_bytes(20));
        $session = $client->getRequest()->getSession();
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    /**
     * Хост создаёт кружок и шарит туда свой лук; гость вступает по инвайту.
     *
     * @return array{0: User, 1: User, 2: WardrobeCircle, 3: WardrobeOutfitShare}
     */
    private function setupCircleWithSharedOutfit(string $hostEmail, string $guestEmail, string $title): array
    {
        $container = static::getContainer();
        $em = $this->em();
        $this->skipIfNoDatabase();

        $host = UserFactory::withEmail($container, $hostEmail);
        $guest = UserFactory::withEmail($container, $guestEmail);
        $circle = $this->service()->create($host, $title);
        $outfit = $this->createOutfit($host, 'Лук '.$title);
        $share = $this->service()->shareToCircle($host, $outfit, $circle);
        self::assertSame(WardrobeOutfitShare::STATUS_ACTIVE, $share->getStatus());
        $invite = $this->service()->createInvite($host, $circle);
        $this->service()->acceptInvite($guest, $invite);

        $hostId = (int) $host->getId();
        $guestId = (int) $guest->getId();
        $circleId = (int) $circle->getId();
        $shareId = (int) $share->getId();
        $em->clear();
        static::ensureKernelShutdown(); // сетап грузил ядро напрямую — гасим до createClient()

        return [
            $em->find(User::class, $hostId),
            $em->find(User::class, $guestId),
            $em->find(WardrobeCircle::class, $circleId),
            $em->find(WardrobeOutfitShare::class, $shareId),
        ];
    }

    /** Семья: родитель + подросток с личным входом (familyRole=child, не managed). */
    private function createTeenWithFamily(string $teenEmail): array
    {
        $container = static::getContainer();
        $em = $this->em();
        /** @var FamilyService $families */
        $families = $container->get(FamilyService::class);

        $parent = UserFactory::withEmail($container, str_replace('@', '-parent@', $teenEmail));
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
}
