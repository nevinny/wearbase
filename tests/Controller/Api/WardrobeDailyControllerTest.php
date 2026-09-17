<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeConsent;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeOutfit;
use App\Service\Wardrobe\PreparedWardrobePhoto;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Агент-API ночного пакетного конвейера образов (/api/v1/wardrobe/daily/*).
 * Аутентификация — X-Agent-Token (см. WardrobeDailyController::authorize), без HMAC.
 */
class WardrobeDailyControllerTest extends WebTestCase
{
    private const TOKEN = 'test-agent-token';

    public function testOutfitsRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/wardrobe/daily/outfits', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testImageQueueRequiresToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/wardrobe/daily/images/queue');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testImageQueueIncludesOnlyConsentedItemsWithPhotos(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $items] = $this->makeWardrobeWithItems($em, 'cutout-queue', 1);
        $items[0]->setPhoto('cutout-source.jpg');
        $consent = $em->getRepository(WardrobeConsent::class)->findOneBy(['subject' => $owner]);
        $consent->grantPhotoProcessing($owner);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/images/queue?after=' . ($items[0]->getId() - 1), [], [], [
            'HTTP_X_AGENT_TOKEN' => self::TOKEN,
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($items[0]->getId(), $body['items'][0]['id']);
        $this->assertSame(64, strlen($body['items'][0]['revision']));
    }

    public function testImageResultStoresSignedPngPrivately(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $items] = $this->makeWardrobeWithItems($em, 'cutout-result', 1);
        $item = $items[0];
        $item->setPhoto('cutout-source.jpg');
        $em->getRepository(WardrobeConsent::class)->findOneBy(['subject' => $owner])->grantPhotoProcessing($owner);
        $em->flush();

        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = PreparedWardrobePhoto::path(static::getContainer()->getParameter('kernel.project_dir'), $item);
        try {
            $client->request('POST', '/api/v1/wardrobe/daily/images/result/' . $item->getId(), [], [], [
                'HTTP_X_AGENT_TOKEN' => self::TOKEN,
                'HTTP_X_SOURCE_REVISION' => PreparedWardrobePhoto::revision($item),
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $bytes, 'test-agent-secret'),
                'CONTENT_TYPE' => 'image/png',
            ], $bytes);
            $this->assertResponseIsSuccessful();
            $this->assertFileExists($path);
            $this->assertSame($bytes, file_get_contents($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAcceptsValidOutfitForOwnedWardrobe(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-accept', 2);

        $body = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WORK,
            'request' => WardrobeOutfit::DAILY_OCCASIONS[WardrobeOutfit::OCCASION_WORK],
            'outfits' => [
                ['title' => 'Строгий образ', 'explanation' => 'Рубашка и брюки', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['created']);
        $this->assertSame([], $data['rejected']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        $this->assertCount(1, $outfits);
        $this->assertSame(WardrobeOutfit::OCCASION_WORK, $outfits[0]->getOccasion());
        $this->assertNull($outfits[0]->getDeletedAt());
        $this->assertSame(
            [$items[0]->getId(), $items[1]->getId()],
            array_column($outfits[0]->getItems(), 'id'),
        );
    }

    /**
     * Коллаж — производная ночного батча (WardrobeOutfitCollageRenderer), она обязана появиться
     * прямо в этом ответе (см. докблок рендерера про «не на лету при открытии страницы»),
     * а не при первом просмотре /account/wardrobe/outfits.
     */
    public function testAcceptedOutfitGetsCollageRenderedByTheBatchEndpointItself(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var \App\Service\Wardrobe\WardrobeOutfitCollageRenderer $collageRenderer */
        $collageRenderer = static::getContainer()->get(\App\Service\Wardrobe\WardrobeOutfitCollageRenderer::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-collage', 2);

        $body = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WORK,
            'request' => WardrobeOutfit::DAILY_OCCASIONS[WardrobeOutfit::OCCASION_WORK],
            'outfits' => [
                ['title' => 'Строгий образ', 'explanation' => 'Рубашка и брюки', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $outfit = $em->getRepository(WardrobeOutfit::class)->findOneBy(['wardrobeOwner' => $owner->getId()]);
        $this->assertNotNull($outfit);

        $path = $collageRenderer->path((int) $outfit->getId());
        try {
            $this->assertFileExists($path);
            $size = getimagesize($path);
            $this->assertSame(
                [\App\Service\Wardrobe\WardrobeOutfitCollageRenderer::WIDTH, \App\Service\Wardrobe\WardrobeOutfitCollageRenderer::HEIGHT],
                [$size[0], $size[1]],
            );
        } finally {
            @unlink($path); // имя файла детерминировано по id — не копим мусор между прогонами
        }
    }

    public function testSkipsWardrobeWithoutPersonalizationConsent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-no-consent', 2, withConsent: false);

        $body = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WORK,
            'request' => 'Образ на работу',
            'outfits' => [['title' => 'Образ', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('consent_denied', $data['status']);
        $this->assertSame(0, $data['created']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        $this->assertCount(0, $outfits);
    }

    public function testRejectsOutfitContainingForeignOrUnknownItemId(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$ownerA, $wardrobeA, $itemsA] = $this->makeWardrobeWithItems($em, 'daily-owner-a', 2);
        [, , $itemsB] = $this->makeWardrobeWithItems($em, 'daily-owner-b', 1);

        $body = json_encode([
            'wardrobe_id' => $wardrobeA->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WALK,
            'request' => 'Образ на прогулку',
            'outfits' => [
                ['title' => 'Валидный', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), $itemsA[1]->getId()]],
                ['title' => 'Чужая вещь', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), $itemsB[0]->getId()]],
                ['title' => 'Несуществующая вещь', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), 9999999]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['created']);
        $this->assertCount(2, $data['rejected']);
        $this->assertSame('unknown_item_id', $data['rejected'][0]['reason']);
        $this->assertSame('unknown_item_id', $data['rejected'][1]['reason']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $ownerA->getId()]);
        $this->assertCount(1, $outfits);
    }

    public function testRerunSameDayAndOccasionReplacesInsteadOfDuplicating(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-idempotent', 2);

        $firstBody = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_MEETING,
            'request' => 'Образ на встречу',
            'outfits' => [['title' => 'Первый прогон', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);
        $this->postOutfits($client, $firstBody);
        $this->assertResponseIsSuccessful();

        $secondBody = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_MEETING,
            'request' => 'Образ на встречу',
            'outfits' => [['title' => 'Повторный прогон', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);
        $this->postOutfits($client, $secondBody);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $all = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        // Оба прогона физически в БД (soft-delete, не DELETE), но активен только последний.
        $this->assertCount(2, $all);
        $active = array_values(array_filter($all, static fn (WardrobeOutfit $o): bool => $o->getDeletedAt() === null));
        $this->assertCount(1, $active);
        $this->assertSame('Повторный прогон', $active[0]->getTitle());
    }

    /**
     * Регрессия: catalog() раньше отдавал вещи через широкий findActiveForUser() (только
     * deletedAt/archive/given_away), минуя строгий фильтр WardrobeStylistContextBuilder
     * (ITEM_ACTIVE + WEAR_ACTIVE + CLEANLINESS_CLEAN) — ночной батч предлагал грязные вещи.
     */
    public function testCatalogExcludesDirtyItemsAndReturnsRotationAndPreferenceContext(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-catalog', 2);
        $dirty = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(99)
            ->setCategory('Рубашки')
            ->setColorName('белый')
            ->setCleanlinessStatus(WardrobeItem::CLEANLINESS_DIRTY);
        $em->persist($dirty);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/catalog', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // БД теста общая на весь прогон phpunit (var/test.db не сбрасывается между методами) —
        // фильтруем ответ до "своего" гардероба, не полагаемся на count($data['wardrobes']).
        $mine = array_values(array_filter(
            $data['wardrobes'],
            static fn (array $w): bool => (int) $w['wardrobe_id'] === $wardrobe->getId(),
        ));
        $this->assertCount(1, $mine);
        $ids = array_column($mine[0]['items'], 'id');
        $this->assertCount(2, $ids);
        $this->assertNotContains($dirty->getId(), $ids);
        // findActiveForUser() сортирует по itemNo DESC — $items[1] (itemNo=2) идёт первым.
        $this->assertSame([$items[1]->getId(), $items[0]->getId()], $ids);
        $this->assertSame('fresh', $mine[0]['items'][0]['rotation']);
        $this->assertArrayHasKey('preference_context', $mine[0]);
    }

    /**
     * Домашний конвейер подготовки атрибутов (app:wardrobe:prepare-prod-items):
     * очередь → фото по id → приём результатов. Без консент-гейта — та же логика,
     * что у PrepareExistingItemsCommand (локальная обработка отдельной отметки
     * не требует, см. WardrobeAiService::externalPhotoConsentRequired()).
     */
    public function testPrepareQueueRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/queue');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testPrepareQueueListsOnlyItemsMissingAttributes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'prepare-queue', 1);
        $needsPrep = (new WardrobeItem())
            ->setUser($items[0]->getUser())
            ->setWardrobe($wardrobe)
            ->setItemNo(50);
        $em->persist($needsPrep);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/queue', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_column($data['items'], 'id');
        // makeWardrobeWithItems() заполняет category+colorName, но НЕ season — тоже
        // "нуждается в подготовке" по тому же критерию, что и findNeedingPreparation().
        $this->assertContains($items[0]->getId(), $ids);
        $this->assertContains($needsPrep->getId(), $ids);

        $row = current(array_filter($data['items'], static fn (array $r): bool => (int) $r['id'] === $needsPrep->getId()));
        $this->assertSame($wardrobe->getId(), $row['wardrobe_id']);
        $this->assertSame($needsPrep->getUser()->getEmail(), $row['owner_email']);
    }

    public function testPrepareQueueExcludesFullyFilledItems(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-queue-full', 0);
        $complete = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(1)
            ->setCategory('Рубашки')
            ->setColorName('белый')
            ->setSeason('summer');
        $em->persist($complete);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/queue', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotContains($complete->getId(), array_column($data['items'], 'id'));
    }

    /**
     * Гардероб id=2 на проде: обложка галереи есть у 40 вещей из 44, основное фото —
     * только у 9 (WardrobeItem.photo). До этой правки prepareQueue() отдавал has_photo
     * только по последнему, а preparePhoto() резолвил только его — 404 на 31 вещи с
     * фото. has_photo обязан учитывать ОБА источника.
     */
    public function testPrepareQueueMarksHasPhotoTrueForGalleryOnlyItem(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-queue-gallery-photo', 0);
        $item = (new WardrobeItem())->setUser($owner)->setWardrobe($wardrobe)->setItemNo(1);
        $em->persist($item);
        $photo = (new WardrobeItemPhoto())->setItem($item)->setFilePath('placeholder.jpg');
        $item->addPhoto($photo);
        $em->persist($photo);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/queue', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $row = current(array_filter($data['items'], static fn (array $r): bool => (int) $r['id'] === $item->getId()));
        $this->assertTrue($row['has_photo']);
    }

    /**
     * Приоритет источников на Mac (WB-карточка → фото → название) читает эти поля из
     * очереди без дополнительных походов на прод.
     */
    public function testPrepareQueueIncludesRoutingFieldsForSourcePriority(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-queue-routing', 0);
        $item = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(1)
            ->setName('Розовое трикотажное поло в полоску NIL')
            ->setCategory('Поло')
            ->setMaterialText('трикотаж')
            ->setProductUrl('https://www.wildberries.ru/catalog/13578826/detail.aspx');
        $em->persist($item);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/queue', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $row = current(array_filter($data['items'], static fn (array $r): bool => (int) $r['id'] === $item->getId()));
        $this->assertFalse($row['has_photo']);
        $this->assertSame('Розовое трикотажное поло в полоску NIL', $row['name']);
        $this->assertSame('Поло', $row['category']);
        $this->assertSame('трикотаж', $row['material_text']);
        $this->assertSame('https://www.wildberries.ru/catalog/13578826/detail.aspx', $row['product_url']);
    }

    public function testPreparePhotoRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/photo/1');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testPreparePhotoReturnsRealFileBytes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-photo', 0);
        $item = (new WardrobeItem())->setUser($owner)->setWardrobe($wardrobe)->setItemNo(1);

        $tmp = tempnam(sys_get_temp_dir(), 'wardrobe_daily_prepare_') . '.jpg';
        $image = imagecreatetruecolor(3, 3);
        imagejpeg($image, $tmp, 90);
        imagedestroy($image);
        $expectedBytes = file_get_contents($tmp);
        $item->setPhotoFile(new UploadedFile($tmp, 'item.jpg', 'image/jpeg', null, true));
        $em->persist($item);
        $em->flush();
        @unlink($tmp);

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/photo/' . $item->getId(), [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($expectedBytes, file_get_contents($response->getFile()->getPathname()));

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        @unlink((string) $storage->resolvePath($item, 'photoFile'));
    }

    /**
     * Регрессия основной правки: вещь БЕЗ основного фото (item.photoFile), но с
     * фото в галерее (WardrobeItemPhoto) — раньше 404, теперь резолвится через
     * item.coverPhoto (тот же порядок, что в карточке ЛК: show.html.twig).
     */
    public function testPreparePhotoResolvesGalleryCoverWhenMainPhotoMissing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-photo-gallery-cover', 0);
        $item = (new WardrobeItem())->setUser($owner)->setWardrobe($wardrobe)->setItemNo(1);
        $em->persist($item);
        $em->flush();

        $tmp = tempnam(sys_get_temp_dir(), 'wardrobe_daily_prepare_gallery_') . '.jpg';
        $image = imagecreatetruecolor(4, 4);
        imagejpeg($image, $tmp, 90);
        imagedestroy($image);
        $expectedBytes = file_get_contents($tmp);

        $photo = (new WardrobeItemPhoto())->setItem($item);
        $photo->setFile(new UploadedFile($tmp, 'gallery.jpg', 'image/jpeg', null, true));
        $item->addPhoto($photo);
        $em->persist($photo);
        $em->flush();
        @unlink($tmp);

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/photo/' . $item->getId(), [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($expectedBytes, file_get_contents($response->getFile()->getPathname()));

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        @unlink((string) $storage->resolvePath($photo, 'file'));
    }

    public function testPreparePhotoReturns404WhenItemHasNoPhoto(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-photo-missing', 0);
        $item = (new WardrobeItem())->setUser($owner)->setWardrobe($wardrobe)->setItemNo(1);
        $em->persist($item);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/prepare/photo/' . $item->getId(), [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPrepareResultsRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/wardrobe/daily/prepare/results', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Критично: category уже заполнена человеком ("Рубашки") — присланное значение
     * должно быть отброшено, а не перезаписать её. colorName и season пусты —
     * применяются. Невалидный season ('unknown') отбрасывается по аллоулисту, но
     * не блокирует применение остальных полей той же вещи.
     */
    public function testPrepareResultsFillsOnlyEmptyFieldsAndDropsInvalidSeason(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-results', 0);
        $item = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(1)
            ->setCategory('Рубашки');
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        $body = json_encode([
            'items' => [
                ['id' => $itemId, 'category' => 'Платья', 'colorName' => 'белый', 'materialText' => 'хлопок', 'season' => 'unknown'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/wardrobe/daily/prepare/results', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'], $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['updated']);
        $this->assertSame([], $data['rejected']);

        $em->clear();
        $reloaded = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame('Рубашки', $reloaded->getCategory());
        $this->assertSame('белый', $reloaded->getColorName());
        $this->assertSame('хлопок', $reloaded->getMaterialText());
        $this->assertNull($reloaded->getSeason());
    }

    /**
     * countryOfOrigin/careText приходят из WB-карточки (WildberriesAdapter::fetchCard())
     * — то же правило «только пустое поле», что и у остальных четырёх.
     */
    public function testPrepareResultsFillsCountryOfOriginAndCareTextOnlyWhenEmpty(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe] = $this->makeWardrobeWithItems($em, 'prepare-results-wb', 0);
        $item = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(1)
            ->setCountryOfOrigin('Россия');
        $em->persist($item);
        $em->flush();
        $itemId = $item->getId();

        $body = json_encode([
            'items' => [
                ['id' => $itemId, 'countryOfOrigin' => 'Китай', 'careText' => 'деликатная стирка'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/wardrobe/daily/prepare/results', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'], $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['updated']);

        $em->clear();
        $reloaded = $em->getRepository(WardrobeItem::class)->find($itemId);
        $this->assertSame('Россия', $reloaded->getCountryOfOrigin());
        $this->assertSame('деликатная стирка', $reloaded->getCareText());
    }

    public function testPrepareResultsRejectsUnknownItemId(): void
    {
        $client = static::createClient();

        $body = json_encode(['items' => [['id' => 999999999, 'colorName' => 'белый']]], JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/wardrobe/daily/prepare/results', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'], $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $data['updated']);
        $this->assertSame('not_found', $data['rejected'][0]['reason']);
    }

    /** @return array{0:User,1:Wardrobe,2:WardrobeItem[]} */
    private function makeWardrobeWithItems(EntityManagerInterface $em, string $emailPrefix, int $itemCount, bool $withConsent = true): array
    {
        $owner = UserFactory::withEmail(static::getContainer(), $emailPrefix . '-' . uniqid('', true) . '@test.local');
        $wardrobe = (new Wardrobe())->setOwner($owner);
        $em->persist($wardrobe);

        if ($withConsent) {
            $consent = new WardrobeConsent($owner, $owner);
            $consent->grantPersonalization($owner);
            $em->persist($consent);
        }

        $items = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $item = (new WardrobeItem())
                ->setUser($owner)
                ->setWardrobe($wardrobe)
                ->setItemNo($i)
                ->setCategory('Рубашки')
                ->setColorName('белый');
            $em->persist($item);
            $items[] = $item;
        }
        $em->flush();

        return [$owner, $wardrobe, $items];
    }

    private function postOutfits(KernelBrowser $client, string $body): void
    {
        $client->request(
            'POST',
            '/api/v1/wardrobe/daily/outfits',
            [],
            [],
            ['HTTP_X_AGENT_TOKEN' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }
}
