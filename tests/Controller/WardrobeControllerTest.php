<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BrandStyle;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeTransfer;
use App\Entity\WardrobeItemLifecycleEvent;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeRemotePhotoFetcher;
use App\Service\Wardrobe\WardrobeItemLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSelectorTextContains('body', 'Полная карточка');
        $this->assertSelectorExists('#wardrobe_item_form_categoryRef');
        $this->assertSelectorExists('#wardrobe_item_form_galleryPhotos');
        $this->assertSelectorExists('select[name="wardrobe_item_form[loveAtFirstSight]"]');
        $this->assertSelectorExists('#wardrobe-form-actions.flex.flex-wrap');

        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[size]'       => 'M',
            'wardrobe_item_form[productUrl]' => 'https://example.com/test-item',
            'wardrobe_item_form[loveAtFirstSight]' => WardrobeItem::LOVE_YES,
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
        $this->assertSame(WardrobeItem::LOVE_YES, $item->getLoveAtFirstSight());
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

    public function testNewItemSavesMultipleGalleryPhotos(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        $firstPath = $this->makeTempImage();
        $secondPath = $this->makeTempImage();

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[name]' => 'Вещь с галереей',
            'wardrobe_item_form[size]' => 'M',
        ]);
        $client->request('POST', '/account/wardrobe/new', $form->getPhpValues(), [
            'wardrobe_item_form' => [
                'galleryPhotos' => [
                    new UploadedFile($firstPath, 'front.png', 'image/png', null, true),
                    new UploadedFile($secondPath, 'back.png', 'image/png', null, true),
                ],
            ],
        ]);

        $this->assertResponseRedirects('/account/wardrobe');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy([
            'user' => $user,
            'name' => 'Вещь с галереей',
        ]);
        self::assertNotNull($item);
        self::assertCount(2, $item->getActivePhotos());
        self::assertNotNull($item->getPhoto());
        self::assertSame(1, count(array_filter(
            $item->getActivePhotos(),
            static fn (WardrobeItemPhoto $photo): bool => $photo->isCover(),
        )));

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        foreach ($item->getActivePhotos() as $photo) {
            $path = $storage->resolvePath($photo, 'file');
            if ($path !== null) {
                $this->tmpFiles[] = $path;
            }
        }
    }

    /**
     * Галерея при РЕДАКТИРОВАНИИ — путь сложнее создания: у вещи уже есть обложка,
     * отрабатывает backfillLegacyCoverRow и продолжается нумерация sortOrder. Именно
     * здесь легко сломать обложку следующей правкой и не заметить.
     */
    public function testEditFormAddsGalleryPhotosKeepingExistingCover(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);
        $em     = static::getContainer()->get('doctrine.orm.entity_manager');

        // Вещь с уже загруженной обложкой (legacy-фото), как после быстрого добавления.
        $crawler = $client->request('GET', '/account/wardrobe/new');
        $form    = $crawler->selectButton('Сохранить')->form(['wardrobe_item_form[name]' => 'Вещь с обложкой']);
        $client->request('POST', '/account/wardrobe/new', $form->getPhpValues(), [
            'wardrobe_item_form' => ['photoFile' => ['file' => new UploadedFile($this->makeTempImage(), 'cover.png', 'image/png', null, true)]],
        ]);
        $this->assertResponseRedirects();

        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['user' => $user, 'name' => 'Вещь с обложкой']);
        self::assertNotNull($item);
        $coverBefore = $item->getPhoto();
        self::assertNotNull($coverBefore, 'обложка должна была загрузиться');

        $crawler = $client->request('GET', '/account/wardrobe/' . $item->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Сохранить')->form(['wardrobe_item_form[name]' => 'Вещь с обложкой']);
        $client->request('POST', '/account/wardrobe/' . $item->getId() . '/edit', $form->getPhpValues(), [
            'wardrobe_item_form' => ['galleryPhotos' => [
                new UploadedFile($this->makeTempImage(), 'second.png', 'image/png', null, true),
                new UploadedFile($this->makeTempImage(), 'third.png', 'image/png', null, true),
            ]],
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $fresh = $em->getRepository(WardrobeItem::class)->find($item->getId());
        self::assertCount(3, $fresh->getActivePhotos(), 'обложка + две новые фотографии');
        self::assertSame($coverBefore, $fresh->getPhoto(), 'существующая обложка не должна подмениться новой');
        self::assertSame(1, count(array_filter(
            $fresh->getActivePhotos(),
            static fn (WardrobeItemPhoto $photo): bool => $photo->isCover(),
        )), 'обложка ровно одна');

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        foreach ($fresh->getActivePhotos() as $photo) {
            $path = $storage->resolvePath($photo, 'file');
            if ($path !== null) {
                $this->tmpFiles[] = $path;
            }
        }
    }

    /** Лишние файлы отсекает форма (422), а не загрузчик 500-й: вещь при этом не создаётся. */
    public function testTooManyGalleryPhotosAreRejectedByForm(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $form    = $crawler->selectButton('Сохранить')->form(['wardrobe_item_form[name]' => 'Девять файлов']);
        $files   = [];
        for ($i = 0; $i < 9; $i++) {
            $files[] = new UploadedFile($this->makeTempImage(), "f{$i}.png", 'image/png', null, true);
        }
        $client->request('POST', '/account/wardrobe/new', $form->getPhpValues(), ['wardrobe_item_form' => ['galleryPhotos' => $files]]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('не больше 8', (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertNull($em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Девять файлов']));
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

    public function testFullEditFormSavesLoveAtFirstSight(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setName('Куртка');
        $em->persist($item);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/' . $item->getId() . '/edit');
        $this->assertSelectorExists('select[name="wardrobe_item_form[loveAtFirstSight]"]');

        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[loveAtFirstSight]' => WardrobeItem::LOVE_YES,
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe/' . $item->getId());
        $em->clear();
        $saved = $em->find(WardrobeItem::class, $item->getId());
        self::assertSame(WardrobeItem::LOVE_YES, $saved?->getLoveAtFirstSight());

        $client->request('GET', '/account/wardrobe/' . $item->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Любовь с первого взгляда');
    }

    /**
     * Стиль, удалённый в админке (BrandStyle.status = Deleted), не должен
     * предлагаться к выбору, но уже проставленная связь у вещи не должна
     * пропасть после сохранения формы, и карточка/edit не должны падать.
     */
    public function testDeletedStyleStaysAttachedButIsNotOfferedAsChoice(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $activeStyle = (new BrandStyle())->setTitle('Кэжуал ' . uniqid())->setSlug('casual-' . uniqid());
        $deletedStyle = (new BrandStyle())->setTitle('Удалённый стиль ' . uniqid())->setSlug('deleted-' . uniqid())
            ->setStatus(Statuses::Deleted);
        $em->persist($activeStyle);
        $em->persist($deletedStyle);

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setName('Худи со стилями')
            ->addStyle($activeStyle)
            ->addStyle($deletedStyle);
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId . '/edit');
        $this->assertResponseIsSuccessful();
        // Удалённый стиль не в choice-list — чекбокса для него нет.
        $this->assertCount(0, $crawler->filter('input[value="' . $deletedStyle->getId() . '"]'));
        $this->assertCount(1, $crawler->filter('input[value="' . $activeStyle->getId() . '"]'));

        $client->submit($crawler->selectButton('Сохранить')->form());
        $this->assertResponseRedirects('/account/wardrobe/' . $itemId);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $itemId);
        $styleIds = array_map(static fn (BrandStyle $s): ?int => $s->getId(), $reloaded->getStyles()->toArray());
        self::assertContains($activeStyle->getId(), $styleIds);
        self::assertContains($deletedStyle->getId(), $styleIds, 'Удалённый стиль не должен отвязываться при сохранении формы');
        self::assertCount(2, $styleIds);

        // Карточка вещи не падает и показывает оба стиля (включая удалённый).
        $crawler = $client->request('GET', '/account/wardrobe/' . $itemId);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($activeStyle->getTitle(), $crawler->filter('body')->text());
        $this->assertStringContainsString($deletedStyle->getTitle(), $crawler->filter('body')->text());
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
     * Регресс на 🔴: раньше замена photoFile через «Редактировать» физически удаляла
     * старый файл (Vich delete_on_update) и не заводила для него строку галереи —
     * галерея и обложка расходились с уже удалённым файлом.
     */
    public function testEditingPhotoFileDoesNotDeleteOldFileAndReconcilesGallery(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())->setUser($user)->setItemNo(510)->setCategory('Худи')->setName('Худи с заменой фото');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        // Загружаем первое фото через галерею — как реальный пользователь: реальный
        // физический файл + строка галереи + синхронизация legacy item.photo.
        $client->request('GET', '/account/wardrobe/' . $id);
        $uploadToken = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photos_' . $id);
        $firstPhoto = new UploadedFile($this->makeTempImage(), 'first.png', 'image/png', null, true);
        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos',
            ['_token' => $uploadToken, 'photo_type' => 'product'],
            ['photos' => [$firstPhoto]],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $afterUpload */
        $afterUpload = $em->find(WardrobeItem::class, $id);
        $oldFilePath = $afterUpload->getPhoto();
        $this->assertNotNull($oldFilePath);
        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $oldAbsPath = $storage->resolvePath($afterUpload->getCoverPhoto(), 'file');
        $this->assertFileExists($oldAbsPath);

        // Заменяем фото через форму «Редактировать» (legacy photoFile, в обход галереи).
        $crawler = $client->request('GET', '/account/wardrobe/' . $id . '/edit');
        $secondPhotoPath = $this->makeTempImage();
        $form = $crawler->selectButton('Сохранить')->form();
        $form['wardrobe_item_form[photoFile][file]']->upload($secondPhotoPath);
        $client->submit($form);
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        // Старый файл физически не удалён (проектное правило: никакого DELETE по действию пользователя).
        $this->assertFileExists($oldAbsPath);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertNotSame($oldFilePath, $reloaded->getPhoto());

        $active = $reloaded->getActivePhotos();
        $this->assertCount(2, $active);
        $oldRow = null;
        $newRow = null;
        foreach ($active as $photo) {
            if ($photo->getFilePath() === $oldFilePath) {
                $oldRow = $photo;
            } elseif ($photo->getFilePath() === $reloaded->getPhoto()) {
                $newRow = $photo;
            }
        }
        $this->assertNotNull($oldRow, 'Старое фото осталось строкой галереи');
        $this->assertFalse($oldRow->isCover());
        $this->assertNotNull($newRow, 'Новое фото стало строкой галереи');
        $this->assertTrue($newRow->isCover());
        $this->assertSame($newRow->getId(), $reloaded->getCoverPhoto()?->getId());

        @unlink($oldAbsPath);
        $newAbsPath = $storage->resolvePath($newRow, 'file');
        if ($newAbsPath !== null) {
            @unlink($newAbsPath);
        }
    }

    /**
     * Регресс на 🟠: вещь, созданная обычной формой (legacy photo, без строк галереи) —
     * загрузка второго фото в галерею (напр. «Чек») не должна перебивать основное фото
     * обложкой.
     */
    public function testUploadingSecondPhotoDoesNotOverrideLegacyPrimaryPhoto(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)->setItemNo(511)->setCategory('Обувь')->setName('Кроссовки')
            ->setPhoto('legacy-primary.png');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();
        $this->assertCount(0, $item->getActivePhotos());

        $client->request('GET', '/account/wardrobe/' . $id);
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photos_' . $id);
        $receipt = new UploadedFile($this->makeTempImage(), 'receipt.png', 'image/png', null, true);
        $client->request(
            'POST',
            '/account/wardrobe/' . $id . '/photos',
            ['_token' => $token, 'photo_type' => 'receipt'],
            ['photos' => [$receipt]],
        );
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertCount(2, $reloaded->getActivePhotos());
        $cover = $reloaded->getCoverPhoto();
        $this->assertNotNull($cover);
        $this->assertSame('legacy-primary.png', $cover->getFilePath());
        $this->assertSame('legacy-primary.png', $reloaded->getPhoto());

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        foreach ($reloaded->getActivePhotos() as $photo) {
            if ($photo->getFilePath() !== 'legacy-primary.png') {
                $path = $storage->resolvePath($photo, 'file');
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }
    }

    public function testSetCoverOnAlreadyDeletedPhotoDoesNotCause500(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$item, $photo1, $photo2] = $this->createItemWithTwoPhotos($em, $user, 512);
        $id = $item->getId();
        $photo1Id = $photo1->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $deleteToken = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photo_' . $photo1Id);
        $client->request('POST', '/account/wardrobe/' . $id . '/photos/' . $photo1Id . '/delete', ['_token' => $deleteToken]);
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        // Повторный сабмит (двойной клик / вторая вкладка) по уже удалённому фото —
        // раньше InvalidArgumentException долетала до 500.
        $client->request('GET', '/account/wardrobe/' . $id);
        $secondToken = $this->forceCsrfToken($client->getRequest(), 'wardrobe_photo_' . $photo1Id);
        $client->request('POST', '/account/wardrobe/' . $id . '/photos/' . $photo1Id . '/cover', ['_token' => $secondToken]);
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $client->request('POST', '/account/wardrobe/' . $id . '/photos/' . $photo1Id . '/delete', ['_token' => $secondToken]);
        $this->assertResponseRedirects('/account/wardrobe/' . $id);

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertCount(1, $reloaded->getActivePhotos());
        $this->assertSame($photo2->getId(), $reloaded->getCoverPhoto()?->getId());
    }

    /**
     * IDOR: чужая вещь/фото недоступны ни на одном из трёх photo-эндпоинтов; ответ —
     * 404 (не 403 и не 500), существование чужой сущности не палится.
     */
    public function testPhotoEndpointsReturn404ForForeignItem(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        [$foreignItem, $foreignPhoto1, $foreignPhoto2] = $this->createItemWithTwoPhotos($em, $otherUser, 513);
        $foreignId = $foreignItem->getId();
        $foreignPhotoId = $foreignPhoto1->getId();

        $photo = new UploadedFile($this->makeTempImage(), 'photo.png', 'image/png', null, true);
        $client->request(
            'POST',
            '/account/wardrobe/' . $foreignId . '/photos',
            ['_token' => 'irrelevant', 'photo_type' => 'product'],
            ['photos' => [$photo]],
        );
        $this->assertResponseStatusCodeSame(404);

        $client->request('POST', '/account/wardrobe/' . $foreignId . '/photos/' . $foreignPhotoId . '/cover', ['_token' => 'irrelevant']);
        $this->assertResponseStatusCodeSame(404);

        $client->request('POST', '/account/wardrobe/' . $foreignId . '/photos/' . $foreignPhotoId . '/delete', ['_token' => 'irrelevant']);
        $this->assertResponseStatusCodeSame(404);

        $em->clear();
        /** @var WardrobeItemPhoto $stillActive */
        $stillActive = $em->find(WardrobeItemPhoto::class, $foreignPhotoId);
        $this->assertNull($stillActive->getDeletedAt());
        // Ничего не изменилось: обложка осталась исходной (photo1, как в createItemWithTwoPhotos).
        $this->assertSame($foreignPhoto1->getId(), $em->find(WardrobeItem::class, $foreignId)->getCoverPhoto()?->getId());
    }

    /**
     * Регресс на жёлтую находку: у проданной/подаренной/потерянной вещи «В архив» и
     * «Вернуть» не должны молча перезаписывать терминальный статус.
     */
    public function testArchiveAndRestoreDoNotOverwriteTerminalStatus(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)->setItemNo(514)->setCategory('Платья')->setName('Проданное платье')
            ->setItemStatus(WardrobeItem::ITEM_SOLD);
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $crawler = $client->request('GET', '/account/wardrobe/' . $id);
        // Ни «В архив», ни «Вернуть» для проданной вещи не показываются.
        $this->assertCount(0, $crawler->selectButton('В архив'));
        $this->assertCount(0, $crawler->selectButton('Вернуть в гардероб'));
        $this->assertStringContainsString('Продана', $crawler->filter('body')->text());

        // Прямой POST в обход UI (форсированный CSRF) — сервис тоже должен отказать.
        $archiveToken = $this->forceCsrfToken($client->getRequest(), 'archive_wardrobe_item_' . $id);
        $client->request('POST', '/account/wardrobe/' . $id . '/archive', ['_token' => $archiveToken]);
        $this->assertResponseRedirects('/account/wardrobe');

        $em->clear();
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertSame(WardrobeItem::ITEM_SOLD, $reloaded->getItemStatus());

        $client->request('GET', '/account/wardrobe/' . $id);
        $restoreToken = $this->forceCsrfToken($client->getRequest(), 'restore_wardrobe_item_' . $id);
        $client->request('POST', '/account/wardrobe/' . $id . '/restore', ['_token' => $restoreToken]);
        $this->assertResponseRedirects('/account/wardrobe?view=archive');

        $em->clear();
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertSame(WardrobeItem::ITEM_SOLD, $reloaded->getItemStatus());
    }

    #[DataProvider('archivableStatusProvider')]
    public function testRepairAndTransferredItemsCanBeArchivedFromShowPage(string $itemStatus): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setCategory('Куртки')->setName('Куртка ' . $itemStatus)
            ->setItemStatus($itemStatus);
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $crawler = $client->request('GET', '/account/wardrobe/' . $id);
        $this->assertCount(1, $crawler->selectButton('В архив'));

        $client->submit($crawler->selectButton('В архив')->form());
        $this->assertResponseRedirects('/account/wardrobe');

        $em->clear();
        $reloaded = $em->find(WardrobeItem::class, $id);
        $this->assertSame(WardrobeItem::ITEM_ARCHIVED, $reloaded->getItemStatus());
    }

    /** @return array<string, array{string}> */
    public static function archivableStatusProvider(): array
    {
        return [
            'repair' => [WardrobeItem::ITEM_REPAIR],
            'transferred' => [WardrobeItem::ITEM_TRANSFERRED],
        ];
    }

    /**
     * item_status=active + wear_status=given_away раньше не попадал ни в активный
     * список, ни в архив (см. review) — теперь достижим через явный ?wear=given_away
     * (ссылка из статистики «Статус носки»).
     */
    public function testGivenAwayActiveItemIsReachableThroughWearFilter(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setCategory('Куртки')->setName('Куртка отданная')
            ->setItemStatus(WardrobeItem::ITEM_ACTIVE)
            ->setWearStatus(WardrobeItem::WEAR_GIVEN_AWAY);
        $em->persist($item);
        $em->flush();

        // Не видна ни в обычном списке...
        $crawler = $client->request('GET', '/account/wardrobe');
        $this->assertStringNotContainsString('Куртка отданная', $crawler->filter('body')->text());

        // ...ни в архиве (itemStatus всё ещё active, а не archived/sold/donated/lost).
        $crawler = $client->request('GET', '/account/wardrobe?view=archive');
        $this->assertStringNotContainsString('Куртка отданная', $crawler->filter('body')->text());

        // Но достижима через явный фильтр по статусу носки.
        $crawler = $client->request('GET', '/account/wardrobe?wear=' . WardrobeItem::WEAR_GIVEN_AWAY);
        $this->assertStringContainsString('Куртка отданная', $crawler->filter('body')->text());
    }

    /**
     * N+1 в списке (yellow): searchForUser должен тянуть photos одним fetch-join'ом,
     * а не лениво на каждую карточку.
     */
    public function testSearchForUserEagerLoadsPhotosCollection(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$item] = $this->createItemWithTwoPhotos($em, $user, 515);
        $itemId = $item->getId();
        $em->clear();

        /** @var WardrobeItemRepository $repo */
        $repo = static::getContainer()->get(WardrobeItemRepository::class);
        $items = $repo->searchForUser($user, ['q' => '', 'category' => '', 'brand' => '', 'color' => '', 'size' => '', 'season' => '', 'completion' => '']);

        $found = null;
        foreach ($items as $candidate) {
            if ($candidate->getId() === $itemId) {
                $found = $candidate;
                break;
            }
        }
        $this->assertNotNull($found);
        $photos = $found->getPhotos();
        $this->assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $photos);
        $this->assertTrue($photos->isInitialized(), 'photos должны быть eager-загружены fetch-join, а не лениво');
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

    public function testStatusAndWearFiltersNarrowResults(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $start = $em->getRepository(WardrobeItem::class)->count([]) + 8000;

        $inRepair = (new WardrobeItem())
            ->setUser($user)->setItemNo($start)
            ->setName('Куртка в ремонте')->setCategory('Куртки')
            ->setItemStatus(WardrobeItem::ITEM_REPAIR);
        $onWear = (new WardrobeItem())
            ->setUser($user)->setItemNo($start + 1)
            ->setName('Активная толстовка')->setCategory('Худи')
            ->setWearStatus(WardrobeItem::WEAR_RESERVE);
        $em->persist($inRepair);
        $em->persist($onWear);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe', ['status' => WardrobeItem::ITEM_REPAIR]);
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Куртка в ремонте', $body);
        $this->assertStringNotContainsString('Активная толстовка', $body);

        $crawler = $client->request('GET', '/account/wardrobe', ['wear' => WardrobeItem::WEAR_RESERVE]);
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Активная толстовка', $body);
        $this->assertStringNotContainsString('Куртка в ремонте', $body);
    }

    public function testArchiveViewResetsIncompatibleStatusAndWearFilters(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $start = $em->getRepository(WardrobeItem::class)->count([]) + 8100;

        $archivedItem = (new WardrobeItem())
            ->setUser($user)->setItemNo($start)
            ->setName('Архивная куртка')->setCategory('Куртки')
            ->setItemStatus(WardrobeItem::ITEM_ARCHIVED);
        $em->persist($archivedItem);
        $em->flush();

        // status=repair не имеет смысла в архиве (в архиве только archived) —
        // контроллер должен сбросить его, а не отдать пустой список.
        $crawler = $client->request('GET', '/account/wardrobe', [
            'view' => 'archive',
            'status' => WardrobeItem::ITEM_REPAIR,
            'wear' => WardrobeItem::WEAR_RESERVE,
        ]);
        $this->assertResponseIsSuccessful();
        // Если бы status=repair не сбросился, он пересёкся бы с archiveStatuses
        // (только status=archived проходит в архивном виде) и вещь пропала бы.
        $this->assertStringContainsString('Архивная куртка', $crawler->filter('body')->text());
    }

    /**
     * Плитки статистики «Продана»/«Подарена»/«Потеряна» ссылаются на
     * view=archive&status=X — без view=archive они вели бы в пустой список,
     * т.к. вне архива такой status сбрасывается контроллером.
     */
    #[DataProvider('archiveOnlyStatusProvider')]
    public function testSoldDonatedLostStatisticsTileLeadsToNonEmptyList(string $itemStatus): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($user))
            ->setCategory('Платья')->setName('Вещь со статусом ' . $itemStatus)
            ->setItemStatus($itemStatus);
        $em->persist($item);
        $em->flush();

        // Без view=archive такой status сбрасывается — список пуст.
        $crawler = $client->request('GET', '/account/wardrobe', ['status' => $itemStatus]);
        $this->assertStringNotContainsString('Вещь со статусом ' . $itemStatus, $crawler->filter('body')->text());

        // Со view=archive (как в ссылке из статистики) вещь видна.
        $crawler = $client->request('GET', '/account/wardrobe', ['view' => 'archive', 'status' => $itemStatus]);
        $this->assertStringContainsString('Вещь со статусом ' . $itemStatus, $crawler->filter('body')->text());
    }

    /** @return array<string, array{string}> */
    public static function archiveOnlyStatusProvider(): array
    {
        return [
            'sold' => [WardrobeItem::ITEM_SOLD],
            'donated' => [WardrobeItem::ITEM_DONATED],
            'lost' => [WardrobeItem::ITEM_LOST],
        ];
    }

    public function testStatisticsShowOnlyCurrentUsersAggregates(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $start = $em->getRepository(WardrobeItem::class)->count([]) + 7000;

        $own = (new WardrobeItem())
            ->setUser($user)->setItemNo($start)
            ->setName('Своя статистическая вещь')
            ->setCategory('Уникальная своя категория')
            ->setCustomBrandName('Свой статистический бренд')
            ->setPrice('1234.00')
            ->setCompletionStatus(WardrobeItem::COMPLETION_COMPLETE);
        $foreign = (new WardrobeItem())
            ->setUser(UserFactory::brandOwner(static::getContainer()))->setItemNo($start)
            ->setName('Чужая статистическая вещь')
            ->setCategory('Секретная чужая категория')
            ->setCustomBrandName('Чужой статистический бренд')
            ->setPrice('999999.00');
        $em->persist($own);
        $em->persist($foreign);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/statistics');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Статистика гардероба', $body);
        $this->assertStringContainsString('Уникальная своя категория', $body);
        $this->assertStringContainsString('Свой статистический бренд', $body);
        $this->assertStringNotContainsString('Секретная чужая категория', $body);
        $this->assertStringNotContainsString('Чужой статистический бренд', $body);
    }

    public function testStatisticsShowsFamilyComparisonForMultipleMembers(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-stats-parent@test.local');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Витя');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())
            ->setUser($child)->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($child))
            ->setCategory('Худи')->setName('Худи Вити');
        $em->persist($item);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/statistics');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Семейный гардероб', $crawler->filter('body')->text());
        $this->assertStringContainsString('Витя', $crawler->filter('body')->text());
    }

    /**
     * Не-parent член семьи не должен видеть сравнение по остальным (та же
     * авторизация, что и у FamilyService::resolveMember — canManage).
     */
    public function testFamilyComparisonHidesOtherMembersFromNonParentChild(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-wardrobe-stats-child-parent@test.local');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Ксюша');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $parentItem = (new WardrobeItem())
            ->setUser($parent)->setItemNo($em->getRepository(WardrobeItem::class)->nextItemNo($parent))
            ->setCategory('Секретная родительская категория')->setName('Родительская куртка')->setPrice('50000.00');
        $em->persist($parentItem);
        $em->flush();

        $client->loginUser($child);
        $crawler = $client->request('GET', '/account/wardrobe/statistics');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringNotContainsString('Семейный гардероб', $body);
        $this->assertStringNotContainsString('Секретная родительская категория', $body);
        $this->assertStringNotContainsString('50 000', $body);
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

    #[DataProvider('careTypeProvider')]
    public function testCareAndRepairLifecycle(string $type): void
    {
        $user = UserFactory::withEmail(static::getContainer(), 'harness-care-'.str_replace('_', '-', $type).'@test.local');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())->setUser($user)->setOriginalOwner($user)->setItemNo(1)->setName('Вещь для ухода');
        $em->persist($item);
        $em->flush();
        /** @var WardrobeItemLifecycleService $service */
        $service = static::getContainer()->get(WardrobeItemLifecycleService::class);

        $event = $service->sendToCare($user, $user, $item, $type, 'Мастерская', '750', 'Проверить качество');
        $this->assertSame(WardrobeItem::ITEM_REPAIR, $item->getItemStatus());
        $this->assertSame(WardrobeItemLifecycleEvent::STATUS_OPEN, $event->getStatus());
        $this->assertSame('750.00', $event->getCost());

        $service->completeCare($user, $user, $event);
        $this->assertSame(WardrobeItemLifecycleEvent::STATUS_COMPLETED, $event->getStatus());
        $this->assertSame(WardrobeItem::ITEM_ACTIVE, $item->getItemStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function careTypeProvider(): iterable
    {
        yield 'dry cleaning' => [WardrobeItemLifecycleEvent::TYPE_DRY_CLEANING];
        yield 'hemming' => [WardrobeItemLifecycleEvent::TYPE_REPAIR_HEM];
        yield 'zipper' => [WardrobeItemLifecycleEvent::TYPE_REPAIR_ZIPPER];
        yield 'sole' => [WardrobeItemLifecycleEvent::TYPE_REPAIR_SOLE];
    }

    public function testParentTransfersChildItemOutsideFamilyAndChildCannot(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-external-parent@test.local');
        /** @var FamilyService $families */
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Лера');
        $item = (new WardrobeItem())->setUser($child)->setOriginalOwner($child)->setItemNo(1)->setName('Куртка');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($item);
        $em->flush();
        /** @var WardrobeItemLifecycleService $service */
        $service = static::getContainer()->get(WardrobeItemLifecycleService::class);

        try {
            $service->transferOutside($child, $child, $item, 'Благотворительный фонд', null);
            $this->fail('Minor must not transfer outside without parent');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $event = $service->transferOutside($parent, $child, $item, 'Благотворительный фонд', 'В хорошем состоянии');
        $this->assertSame(WardrobeItem::ITEM_DONATED, $item->getItemStatus());
        $this->assertSame(WardrobeItem::WEAR_GIVEN_AWAY, $item->getWearStatus());
        $this->assertSame(WardrobeItemLifecycleEvent::STATUS_COMPLETED, $event->getStatus());
        $this->assertSame($parent->getId(), $event->getActor()->getId());
        $this->assertSame($child->getId(), $event->getProfileSubject()->getId());
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
