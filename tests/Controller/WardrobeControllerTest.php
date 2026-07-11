<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeTransfer;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration tests for "Мой гардероб": /account/wardrobe/*
 *
 * Run with: php bin/phpunit tests/Controller/WardrobeControllerTest.php
 */
class WardrobeControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe');

        $this->assertResponseRedirects('/login', 302);
    }

    public function testIndexShowsEmptyState(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('GET', '/account/wardrobe');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Мой гардероб');
        $this->assertSelectorTextContains('body', 'пусто');
    }

    public function testNewItemFormCreatesWardrobeItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[category]' => 'Футболки',
            'wardrobe_item_form[name]'     => 'Тестовая футболка',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe');

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['user' => $user, 'name' => 'Тестовая футболка']);

        $this->assertNotNull($item);
        $this->assertSame(1, $item->getItemNo());
        $this->assertSame(WardrobeItem::SOURCE_WEB, $item->getSource());
    }

    public function testIndexShowsStats(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item1 = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(101)
            ->setCategory('Худи')
            ->setName('Худи чёрное')
            ->setPrice('3500.00');
        $item2 = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(102)
            ->setCategory('Ботинки')
            ->setName('Ботинки зимние')
            ->setPrice('6500.00');
        $em->persist($item1);
        $em->persist($item2);
        $em->flush();

        $client->request('GET', '/account/wardrobe');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Худи');
        $this->assertSelectorTextContains('body', 'Ботинки');
        $this->assertSelectorTextContains('body', '10 000');
    }

    public function testShowAndEditReturn404ForOtherUsersItem(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        $foreignItem = (new WardrobeItem())
            ->setUser($otherUser)
            ->setItemNo(201)
            ->setCategory('Платья')
            ->setName('Чужое платье');
        $em->persist($foreignItem);
        $em->flush();
        $id = $foreignItem->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/account/wardrobe/' . $id . '/edit');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteOtherUsersItemReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        $foreignItem = (new WardrobeItem())
            ->setUser($otherUser)
            ->setItemNo(202)
            ->setCategory('Платья')
            ->setName('Чужое платье 2');
        $em->persist($foreignItem);
        $em->flush();
        $id = $foreignItem->getId();

        $csrfToken = $this->grabDeleteCsrfToken($client, $em, $user);

        $client->request('POST', '/account/wardrobe/' . $id . '/delete', ['_token' => $csrfToken]);
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Токен 'delete_wardrobe_item' одинаков для всех вещей — берём его со страницы
     * show своей (только что созданной служебной) вещи, чтобы не лезть в CSRF-сессию напрямую.
     */
    private function grabDeleteCsrfToken($client, EntityManagerInterface $em, User $user): string
    {
        $tokenItem = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->count([]) + 9000)
            ->setCategory('Служебное')
            ->setName('Служебная вещь для CSRF');
        $em->persist($tokenItem);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/' . $tokenItem->getId());

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    public function testDeleteSoftDeletesItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(301)
            ->setCategory('Ремни')
            ->setName('Ремень кожаный');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $crawler   = $client->request('GET', '/account/wardrobe/' . $id);
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/account/wardrobe/' . $id . '/delete', ['_token' => $csrfToken]);
        $this->assertResponseRedirects('/account/wardrobe');

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->getRepository(WardrobeItem::class)->find($id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->getDeletedAt());

        $crawler = $client->request('GET', '/account/wardrobe');
        // в тест-БД могут жить вещи из соседних тестов — проверяем отсутствие именно этой
        $this->assertStringNotContainsString('Ремень кожаный', $crawler->filter('body')->text());
    }

    public function testShowDeletedItemReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(401)
            ->setCategory('Сапоги')
            ->setName('Сапоги удалённые');
        $item->softDelete();
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Семейный гардероб: ?member=, transfer, status ─────────────────────

    public function testMemberIndexAndCreateUseChildOwnership(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-parent1@test.local');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Оля');

        $crawler = $client->request('GET', '/account/wardrobe?member=' . $child->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Оля');

        $crawler = $client->request('GET', '/account/wardrobe/new?member=' . $child->getId());
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[category]' => 'Платья',
            'wardrobe_item_form[name]'     => 'Платье для Оли',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe?member=' . $child->getId());

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Платье для Оли']);

        $this->assertNotNull($item);
        $this->assertSame($child->getId(), $item->getUser()->getId());
        $this->assertSame($child->getId(), $item->getOriginalOwner()->getId());
        $this->assertSame(1, $item->getItemNo());
    }

    public function testForeignMemberAccessIsDenied(): void
    {
        $client = static::createClient();
        $actor = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-parent2@test.local');
        $client->loginUser($actor);

        // Посторонний вне семьи actor'а
        $stranger = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-stranger@test.local');

        $client->request('GET', '/account/wardrobe?member=' . $stranger->getId());

        // FamilyService::resolveMember() кидает Security\Core\AccessDeniedException —
        // ядро превращает её в 403, а не в редирект на логин (actor уже аутентифицирован).
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTransferMovesItemToChildAndRenumbers(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-parent3@test.local');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Костя');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($parent)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($parent))
            ->setCategory('Куртки')
            ->setName('Куртка мамина')
            ->setOriginalOwner($parent);
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        $countBefore = (int) $em->getRepository(WardrobeItem::class)->countActiveForUser($parent);

        // Транспортная форма рендерится только у parent'а (transferTargets непусты) —
        // берём CSRF-токен со страницы show своей же вещи.
        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId);
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Передать')->form([
            'to_user' => (string) $child->getId(),
            'note'    => 'на вырост',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe/' . $itemId . '?member=' . $child->getId());

        $em->clear();
        /** @var WardrobeItem $reloadedItem */
        $reloadedItem = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame($child->getId(), $reloadedItem->getUser()->getId());
        $this->assertSame(1, $reloadedItem->getItemNo());

        $transfer = $em->getRepository(WardrobeTransfer::class)->findOneBy(['item' => $reloadedItem]);
        $this->assertNotNull($transfer);
        $this->assertSame($parent->getId(), $transfer->getFromUser()->getId());
        $this->assertSame($child->getId(), $transfer->getToUser()->getId());
        $this->assertSame('на вырост', $transfer->getNote());

        /** @var User $reloadedParent */
        $reloadedParent = $em->getRepository(User::class)->find($parent->getId());
        $countAfter = (int) $em->getRepository(WardrobeItem::class)->countActiveForUser($reloadedParent);
        $this->assertSame($countBefore - 1, $countAfter);
    }

    public function testTransferByNonParentIsForbidden(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-parent4@test.local');

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Ваня');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($parent)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($parent))
            ->setCategory('Обувь')
            ->setName('Мамины туфли')
            ->setOriginalOwner($parent);
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        // Ребёнок логинится под своей учёткой; передать чужую вещь — не парент, доступа нет.
        $client->loginUser($child);
        // Реальный запрос нужен, чтобы у клиента установилась сессия (для cookie).
        $client->request('GET', '/account/wardrobe');
        $this->assertResponseIsSuccessful();

        // Транспортная форма 'transfer_wardrobe_item' никогда не рендерится не-parent'у —
        // токен для той же сессии генерируем напрямую через CSRF-менеджер контейнера
        // (тот же трюк, что и для CSRF без реального рендера формы в тестах Symfony).
        $token = $this->forceCsrfToken($client->getRequest(), 'transfer_wardrobe_item');

        $client->request('POST', '/account/wardrobe/' . $itemId . '/transfer', [
            'to_user' => (string) $parent->getId(),
            'note'    => 'нельзя',
            '_token'  => $token,
        ]);

        $this->assertResponseStatusCodeSame(403);

        $em->clear();
        /** @var WardrobeItem $reloadedItem */
        $reloadedItem = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame($parent->getId(), $reloadedItem->getUser()->getId());
    }

    public function testStatusTransitionsAndInvalidStatusIsIgnored(): void
    {
        $client = static::createClient();
        $user   = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-status@test.local');
        $client->loginUser($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setCategory('Худи')
            ->setName('Худи на вырост');
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        // active → outgrown: вещь остаётся видна в индексе
        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId);
        $form = $crawler->selectButton(WardrobeItem::WEAR_LABELS[WardrobeItem::WEAR_OUTGROWN])->form();
        $client->submit($form);
        $this->assertResponseRedirects('/account/wardrobe/' . $itemId);

        $em->clear();
        /** @var WardrobeItem $reloadedItem */
        $reloadedItem = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame(WardrobeItem::WEAR_OUTGROWN, $reloadedItem->getWearStatus());

        $crawler = $client->request('GET', '/account/wardrobe');
        $this->assertStringContainsString('Худи на вырост', $crawler->filter('body')->text());

        // outgrown → given_away: пропадает из индекса и статистики, но не soft-deleted
        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId);
        $form = $crawler->selectButton(WardrobeItem::WEAR_LABELS[WardrobeItem::WEAR_GIVEN_AWAY])->form();
        $client->submit($form);
        $this->assertResponseRedirects('/account/wardrobe/' . $itemId);

        $em->clear();
        /** @var WardrobeItem $reloadedItem */
        $reloadedItem = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame(WardrobeItem::WEAR_GIVEN_AWAY, $reloadedItem->getWearStatus());
        $this->assertNull($reloadedItem->getDeletedAt());

        $crawler = $client->request('GET', '/account/wardrobe');
        $this->assertStringNotContainsString('Худи на вырост', $crawler->filter('body')->text());

        /** @var User $reloadedUser */
        $reloadedUser = $em->getRepository(User::class)->find($user->getId());
        $stats = $em->getRepository(WardrobeItem::class)->getStats($reloadedUser);
        $this->assertSame(0, (int) array_sum(array_column($stats, 'cnt')));

        // Невалидный wear_status: значение не применяется, статус остаётся прежним
        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId);
        $csrfToken = $crawler->filter('form')->reduce(
            static fn ($node) => str_contains((string) $node->attr('action'), '/status'),
        )->first()->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/account/wardrobe/' . $itemId . '/status', [
            'wear_status' => 'not-a-real-status',
            '_token'      => $csrfToken,
        ]);
        $this->assertResponseRedirects('/account/wardrobe/' . $itemId);

        $em->clear();
        /** @var WardrobeItem $reloadedItem */
        $reloadedItem = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame(WardrobeItem::WEAR_GIVEN_AWAY, $reloadedItem->getWearStatus());
    }

    /**
     * Форсирует CSRF-токен для роли, у которой в UI никогда не рендерится соответствующая
     * форма (напр. transfer — только у parent'а), но которая всё равно проходит через
     * isCsrfTokenValid() в контроллере. Токен генерируется в СЕССИИ последнего реального
     * запроса клиента (тот же request, та же cookie) — иначе он не совпадёт с тем, что
     * проверит контроллер на следующем запросе.
     */
    private function forceCsrfToken(Request $lastRequest, string $tokenId): string
    {
        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();

        return $token;
    }
}
