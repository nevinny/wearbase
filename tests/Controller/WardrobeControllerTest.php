<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeTransfer;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeRemotePhotoFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Integration tests for "Мой гардероб": /account/wardrobe/*
 *
 * Run with: php bin/phpunit tests/Controller/WardrobeControllerTest.php
 */
class WardrobeControllerTest extends AuthenticatedWebTestCase
{
    /** @var string[] absolute paths of files created by photo tests, cleaned up in tearDown */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
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
            'wardrobe_item_form[size]'       => 'M',
            'wardrobe_item_form[productUrl]' => 'https://example.com/test-item',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe');

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['user' => $user, 'productUrl' => 'https://example.com/test-item']);

        $this->assertNotNull($item);
        $this->assertSame(1, $item->getItemNo());
        $this->assertSame(WardrobeItem::SOURCE_WEB, $item->getSource());
        $this->assertSame(WardrobeItem::COMPLETION_DRAFT, $item->getCompletionStatus());
        $this->assertNotNull($item->getWardrobe());
        $this->assertSame($user->getId(), $item->getWardrobe()->getOwner()?->getId());
        $this->assertTrue($item->getWardrobe()->isDefault());
    }

    public function testSaveAndAddNextReturnsToQuickForm(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $form = $crawler->selectButton('Сохранить и добавить следующую')->form([
            'wardrobe_item_form[size]' => 'S',
            'wardrobe_item_form[productUrl]' => 'https://example.com/next-item',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe/new');
    }

    public function testQuickFormPersistsWildberriesPreviewAsPhoto(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->loginAsCustomer($client);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $requests = 0;
        static::getContainer()->set(
            WardrobeRemotePhotoFetcher::class,
            new WardrobeRemotePhotoFetcher(new MockHttpClient(
                static function () use (&$requests, $png): MockResponse {
                    $requests++;
                    return new MockResponse($png ?: '', ['http_code' => 200]);
                },
            )),
        );
        $productUrl = 'https://www.wildberries.ru/catalog/' . random_int(100000, 999999) . '/detail.aspx';

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[size]' => 'M',
            'wardrobe_item_form[productUrl]' => $productUrl,
            'wardrobe_item_form[remotePhotoUrl]' => 'https://basket-01.wbbasket.ru/vol1/part1/123/images/big/1.webp',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe');
        self::assertSame(1, $requests);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy([
            'user' => $user,
            'productUrl' => $productUrl,
        ]);
        self::assertNotNull($item?->getPhoto());

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $path = $storage->resolvePath($item, 'photoFile');
        self::assertNotNull($path);
        self::assertFileExists($path);
        @unlink($path);
    }

    public function testFullEditFormSavesCategoryAndItemStatus(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $category = (new WardrobeCategory())->setCode('review-test-top-' . uniqid())->setName('Топ')->setSortOrder(1);
        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setCategory('Старое значение')
            ->setName('Черновик');
        $em->persist($category);
        $em->persist($item);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/' . $item->getId() . '/edit');
        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[name]' => 'Голубой топ',
            'wardrobe_item_form[categoryRef]' => (string) $category->getId(),
            'wardrobe_item_form[itemStatus]' => WardrobeItem::ITEM_REPAIR,
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe/' . $item->getId());
        $em->clear();
        $saved = $em->find(WardrobeItem::class, $item->getId());
        self::assertSame('Топ', $saved?->getCategory());
        self::assertSame(WardrobeItem::ITEM_REPAIR, $saved?->getItemStatus());
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

        return $crawler->filter('form[action*="/delete"]:not([action*="/photos/"]) input[name="_token"]')->attr('value');
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
        $csrfToken = $crawler->filter('form[action*="/delete"]:not([action*="/photos/"]) input[name="_token"]')->attr('value');

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

    public function testArchiveHidesItemAndRestoreReturnsIt(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(351)
            ->setCategory('Куртки')
            ->setName('Архивируемая куртка');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $crawler = $client->request('GET', '/account/wardrobe/'.$id);
        $client->submit($crawler->selectButton('В архив')->form());
        $this->assertResponseRedirects('/account/wardrobe');

        $crawler = $client->request('GET', '/account/wardrobe');
        $this->assertStringNotContainsString('Архивируемая куртка', $crawler->filter('body')->text());

        $crawler = $client->request('GET', '/account/wardrobe?view=archive');
        $this->assertStringContainsString('Архивируемая куртка', $crawler->filter('body')->text());
        $client->submit($crawler->selectButton('Вернуть в гардероб')->form());
        $this->assertResponseRedirects('/account/wardrobe?view=archive');

        $crawler = $client->request('GET', '/account/wardrobe');
        $this->assertStringContainsString('Архивируемая куртка', $crawler->filter('body')->text());
    }

    // ── Галерея фото: upload / cover / delete ─────────────────────────────

    public function testUploadPhotoAddsToGalleryAndSyncsLegacyPhoto(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())->setUser($user)->setItemNo(501)->setCategory('Худи')->setName('Худи для фото');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photos_' . $id);
        $photo = new UploadedFile($this->makeTempImage(), 'photo.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos',
            ['_token' => $token, 'photo_type' => 'product'],
            ['photos' => [$photo]],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $activePhotos = $reloaded->getActivePhotos();
        $this->assertCount(1, $activePhotos);
        $cover = $reloaded->getCoverPhoto();
        $this->assertNotNull($cover);
        $this->assertTrue($cover->isCover());
        // Legacy-поле photo синхронизировано с обложкой галереи
        $this->assertSame($cover->getFilePath(), $reloaded->getPhoto());

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $path = $storage->resolvePath($cover, 'file');
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        @unlink($path);
    }

    public function testUploadPhotoWithInvalidCsrfDoesNotMutateItem(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())->setUser($user)->setItemNo(502)->setCategory('Худи')->setName('Худи без фото');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $photo = new UploadedFile($this->makeTempImage(), 'photo.png', 'image/png', null, true);
        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos',
            ['_token' => 'not-a-real-token', 'photo_type' => 'product'],
            ['photos' => [$photo]],
        );
        // Невалидный CSRF — flash + redirect, а не 403; главное, что состояние не меняется
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertCount(0, $reloaded->getActivePhotos());
        $this->assertNull($reloaded->getPhoto());
    }

    public function testSetCoverPhotoSwitchesActiveCoverAndLegacyPhoto(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$item, $photo1, $photo2] = $this->createItemWithTwoPhotos($em, $user, 503);
        $id = $item->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photo_' . $photo2->getId());

        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos/' . $photo2->getId() . '/cover',
            ['_token' => $token],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $cover = $reloaded->getCoverPhoto();
        $this->assertSame($photo2->getId(), $cover->getId());
        $this->assertSame($cover->getFilePath(), $reloaded->getPhoto());
    }

    public function testDeletePhotoSoftDeletesRowAndReassignsCover(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$item, $photo1, $photo2] = $this->createItemWithTwoPhotos($em, $user, 504);
        $id = $item->getId();
        $photo1Id = $photo1->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photo_' . $photo1Id);

        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos/' . $photo1Id . '/delete',
            ['_token' => $token],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItemPhoto $deletedPhoto */
        $deletedPhoto = $em->find(WardrobeItemPhoto::class, $photo1Id);
        // Soft-delete: строка остаётся, но помечена deleted_at
        $this->assertNotNull($deletedPhoto);
        $this->assertNotNull($deletedPhoto->getDeletedAt());

        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $activePhotos = $reloaded->getActivePhotos();
        $this->assertCount(1, $activePhotos);
        $this->assertSame($photo2->getId(), $activePhotos[0]->getId());
        // Обложка перешла на оставшееся фото, legacy-поле photo синхронизировано
        $this->assertTrue($activePhotos[0]->isCover());
        $this->assertSame($activePhotos[0]->getFilePath(), $reloaded->getPhoto());
    }

    public function testPhotoActionsWithInvalidCsrfDoNotMutateState(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$item, $photo1, $photo2] = $this->createItemWithTwoPhotos($em, $user, 505);
        $id = $item->getId();
        $photo1Id = $photo1->getId();
        $photo2Id = $photo2->getId();

        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos/' . $photo2Id . '/cover',
            ['_token' => 'not-a-real-token'],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos/' . $photo1Id . '/delete',
            ['_token' => 'not-a-real-token'],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertCount(2, $reloaded->getActivePhotos());
        $this->assertSame($photo1Id, $reloaded->getCoverPhoto()?->getId());
        /** @var WardrobeItemPhoto $stillActive */
        $stillActive = $em->find(WardrobeItemPhoto::class, $photo1Id);
        $this->assertNull($stillActive->getDeletedAt());
    }

    /**
     * @return array{0: WardrobeItem, 1: WardrobeItemPhoto, 2: WardrobeItemPhoto}
     */
    private function createItemWithTwoPhotos(EntityManagerInterface $em, User $user, int $itemNo): array
    {
        $item = (new WardrobeItem())->setUser($user)->setItemNo($itemNo)->setCategory('Худи')->setName('Худи с галереей');
        $photo1 = (new WardrobeItemPhoto())->setFilePath('gallery-photo-1.png')->setIsCover(true)->setSortOrder(0);
        $photo2 = (new WardrobeItemPhoto())->setFilePath('gallery-photo-2.png')->setIsCover(false)->setSortOrder(1);
        $item->addPhoto($photo1);
        $item->addPhoto($photo2);
        $item->setPhoto($photo1->getFilePath());
        $em->persist($item);
        $em->persist($photo1);
        $em->persist($photo2);
        $em->flush();

        return [$item, $photo1, $photo2];
    }

    public function testSearchAndFiltersStayInsideSelectedUsersWardrobe(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $start = $em->getRepository(WardrobeItem::class)->count([]) + 5000;

        $matching = (new WardrobeItem())
            ->setUser($user)->setItemNo($start)
            ->setName('Льняная рубашка')->setCategory('Рубашки')
            ->setCustomBrandName('Local Brand')->setColorName('Белый')
            ->setSize('M')->setSeason('summer')
            ->setCompletionStatus(WardrobeItem::COMPLETION_BASIC);
        $other = (new WardrobeItem())
            ->setUser($user)->setItemNo($start + 1)
            ->setName('Зимние ботинки')->setCategory('Ботинки')
            ->setCustomBrandName('Other Brand')->setColorName('Чёрный')
            ->setSize('39')->setSeason('winter');
        $foreign = (new WardrobeItem())
            ->setUser(UserFactory::brandOwner(static::getContainer()))->setItemNo($start)
            ->setName('Льняная чужая вещь')->setCategory('Рубашки')
            ->setCustomBrandName('Local Brand')->setSize('M')->setSeason('summer')
            ->setCompletionStatus(WardrobeItem::COMPLETION_BASIC);
        $em->persist($matching);
        $em->persist($other);
        $em->persist($foreign);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe', [
            'q' => 'льняная',
            'category' => 'Рубашки',
            'brand' => 'Local Brand',
            'size' => 'M',
            'season' => 'summer',
            'completion' => 'basic',
        ]);

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Льняная рубашка', $body);
        $this->assertStringNotContainsString('Зимние ботинки', $body);
        $this->assertStringNotContainsString('Льняная чужая вещь', $body);
        $this->assertSelectorTextContains('body', 'Найдено вещей');

        $crawler = $client->request('GET', '/account/wardrobe?q=%23'.$start);
        $this->assertStringContainsString('Льняная рубашка', $crawler->filter('body')->text());
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
            'wardrobe_item_form[size]'       => '128',
            'wardrobe_item_form[productUrl]' => 'https://example.com/child-dress',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe?member=' . $child->getId());

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['productUrl' => 'https://example.com/child-dress']);

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

    // ── AI-подсказки по фото: перезапрос по item_id (уже сохранённая вещь) ────

    public function testAiPhotoWithForeignItemIdReturnsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        $foreignItem = (new WardrobeItem())
            ->setUser($otherUser)
            ->setItemNo(9101)
            ->setCategory('Платья')
            ->setName('Чужое платье для AI');
        $em->persist($foreignItem);
        $em->flush();
        $id = $foreignItem->getId();

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_ai');

        $client->request('POST', '/account/wardrobe/ai/photo', [
            'item_id' => (string) $id,
            '_token'  => $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['ok']);
        // Не палим существование чужой вещи — та же ошибка, что и «вещь не найдена»
        $this->assertSame('Вещь не найдена', $data['error']);
    }

    public function testAiPhotoWithOwnItemWithoutPhotoReturnsError(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(9102)
            ->setCategory('Худи')
            ->setName('Худи без фото для AI');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_ai');

        $client->request('POST', '/account/wardrobe/ai/photo', [
            'item_id' => (string) $id,
            '_token'  => $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame('У вещи нет фото', $data['error']);
    }

    public function testAiPhotoWithoutPhotoOrItemIdKeepsOriginalValidationError(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_ai');

        $client->request('POST', '/account/wardrobe/ai/photo', ['_token' => $token]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame('Файл не получен', $data['error']);
    }

    public function testAiPhotoWithOwnSavedPhotoCallsAiServiceAndReturnsResult(): void
    {
        $client = static::createClient();
        // KernelBrowser по умолчанию перезагружает kernel (а с ним и контейнер) между
        // запросами — это стирает container->set() ниже до того, как POST его увидит.
        $client->disableReboot();
        $user = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(9103)
            ->setCategory('Обувь')
            ->setName('Кроссовки для AI-теста')
            ->setPhoto('wardrobe-ai-controller-test.jpg');
        $em->persist($item);
        $em->flush();

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $absPath = $storage->resolvePath($item, 'photoFile');
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, 'fake-image-bytes');

        $aiMock = $this->createMock(WardrobeAiService::class);
        $aiMock->expects($this->once())
            ->method('suggestFromPhoto')
            ->with($absPath, $this->callback(static fn (User $u): bool => $u->getId() === $user->getId()))
            ->willReturn(['ok' => true, 'fields' => ['category' => 'Обувь'], 'confidence' => 'high']);
        static::getContainer()->set(WardrobeAiService::class, $aiMock);

        try {
            $client->request('GET', '/account/wardrobe');
            $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_ai');

            $client->request('POST', '/account/wardrobe/ai/photo', [
                'item_id' => (string) $item->getId(),
                '_token'  => $token,
            ]);

            $this->assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertTrue($data['ok']);
            $this->assertSame('Обувь', $data['fields']['category']);
        } finally {
            @unlink($absPath);
        }
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

    private function makeTempImage(): string
    {
        $path = sys_get_temp_dir() . '/wardrobe_test_' . uniqid() . '.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
