<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Entity\WardrobeOnboarding;
use App\Entity\WardrobeConsent;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Integration tests for the wardrobe photo batch-ingest flow: /account/wardrobe/ingest/*
 *
 * Run with: php bin/phpunit tests/Controller/WardrobeIngestControllerTest.php
 */
class WardrobeIngestControllerTest extends AuthenticatedWebTestCase
{
    private const CSRF_ID = 'wardrobe_ingest';

    /** @var string[] absolute paths of files created by tests, cleaned up in tearDown */
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

    public function testUploadCreatesPendingDrafts(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $photo1 = new UploadedFile($this->makeTempImage(), 'photo1.png', 'image/png', null, true);
        $photo2 = new UploadedFile($this->makeTempImage(), 'photo2.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/upload',
            ['photoConsent' => '1'],
            ['photos' => [$photo1, $photo2]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame(2, $data['uploaded']);
        $this->assertSame([], $data['rejected']);
        $batch = $data['batch'];

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);

        $drafts = $em->getRepository(WardrobeItemDraft::class)->findBy(['user' => $user, 'batchId' => $batch]);
        $this->assertCount(2, $drafts);
        foreach ($drafts as $draft) {
            $this->assertSame(WardrobeItemDraft::STATUS_PENDING, $draft->getStatus());
            $this->assertNotNull($draft->getPhoto());
            $this->tmpFiles[] = $storage->resolvePath($draft, 'photoFile');
        }
    }

    public function testParentUploadCreatesChildDraftAndResumableOnboarding(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'ingest-onboarding-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Соня');
        $client->loginUser($parent);

        $client->request('GET', '/account/wardrobe?member='.$child->getId());
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $photo = new UploadedFile($this->makeTempImage(), 'child.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/upload?member='.$child->getId(),
            ['photoConsent' => '1'],
            ['photos' => [$photo]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('member='.$child->getId(), $data['reviewUrl']);

        $secondPhoto = new UploadedFile($this->makeTempImage(), 'child-second.png', 'image/png', null, true);
        $client->request(
            'POST',
            '/account/wardrobe/ingest/upload?member='.$child->getId(),
            ['photoConsent' => '1'],
            ['photos' => [$secondPhoto]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        $this->assertResponseIsSuccessful();
        $secondUpload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($data['batch'], $secondUpload['batch']);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $drafts = $em->getRepository(WardrobeItemDraft::class)->findBy(['batchId' => $data['batch']]);
        $this->assertCount(2, $drafts);
        $draft = $drafts[0];
        $this->assertSame($child->getId(), $draft->getUser()->getId());
        foreach ($drafts as $batchDraft) {
            $this->tmpFiles[] = static::getContainer()->get(StorageInterface::class)->resolvePath($batchDraft, 'photoFile');
        }

        $onboarding = $em->getRepository(WardrobeOnboarding::class)->findOneBy(['subject' => $child]);
        $this->assertSame(WardrobeOnboarding::STAGE_CAPSULE, $onboarding->getStage());
        $this->assertSame($data['batch'], $onboarding->getActiveBatchId());
        $this->assertSame(0, $em->getRepository(WardrobeOnboarding::class)->count(['subject' => $parent]));

        $client->request('GET', '/account/wardrobe/media/draft/'.$draft->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $foreign = UserFactory::withEmail(static::getContainer(), 'ingest-media-foreign@test.local');
        $client->loginUser($foreign);
        $client->request('GET', '/account/wardrobe/media/draft/'.$draft->getId());
        $this->assertResponseStatusCodeSame(404);

        $client->restart();
        $client->request('GET', '/account/wardrobe/media/draft/'.$draft->getId());
        $this->assertResponseRedirects('/login', 302);
    }

    public function testUploadRejectsDisallowedMime(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $badFile = new UploadedFile($this->makeTempTextFile(), 'note.txt', 'text/plain', null, true);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/upload',
            ['photoConsent' => '1'],
            ['photos' => [$badFile]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame(0, $data['uploaded']);
        $this->assertCount(1, $data['rejected']);
        $this->assertSame('note.txt', $data['rejected'][0]['name']);

        $this->assertArrayNotHasKey('reviewUrl', $data);
    }

    public function testRepeatedPhotoUploadReturnsExistingDraftWithoutDuplicate(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $countBefore = $em->getRepository(WardrobeItemDraft::class)->count(['user' => $user]);
        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $firstPath = $this->makeTempImage();
        $retryPath = sys_get_temp_dir().'/wardrobe_ingest_retry_'.uniqid().'.png';
        copy($firstPath, $retryPath);
        $this->tmpFiles[] = $retryPath;

        $client->request('POST', '/account/wardrobe/ingest/upload', ['photoConsent' => '1'], [
            'photos' => [new UploadedFile($firstPath, 'same.png', 'image/png', null, true)],
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/account/wardrobe/ingest/upload', ['photoConsent' => '1'], [
            'photos' => [new UploadedFile($retryPath, 'same-retry.png', 'image/png', null, true)],
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['uploaded']);
        $this->assertCount(1, $data['duplicates']);
        $this->assertSame($countBefore + 1, $em->getRepository(WardrobeItemDraft::class)->count(['user' => $user]));
    }

    public function testStatusEndpointReturnsCounts(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $batch = 'batch-status-test-' . uniqid();
        $this->makeDraft($em, $user, $batch, WardrobeItemDraft::STATUS_PENDING);
        $this->makeDraft($em, $user, $batch, WardrobeItemDraft::STATUS_RECOGNIZED);
        $this->makeDraft($em, $user, $batch, WardrobeItemDraft::STATUS_FAILED);

        $client->request('GET', '/account/wardrobe/ingest/' . $batch . '/status');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['pending']);
        $this->assertSame(1, $data['recognized']);
        $this->assertSame(1, $data['failed']);
    }

    public function testAcceptPromotesDraftToWardrobeItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $draft = $this->makeDraft($em, $user, 'batch-accept-' . uniqid(), WardrobeItemDraft::STATUS_RECOGNIZED, [
            'category' => 'Футболки',
            'name'     => 'Распознанное имя',
        ]);
        $draftId = $draft->getId();
        $itemCountBefore = $em->getRepository(WardrobeItem::class)->count([]);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/accept',
            [],
            [],
            [
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_TYPE'      => 'application/json',
            ],
            json_encode(['name' => 'Изменённое имя', 'category' => 'Футболки', 'size' => 'M', 'notes' => 'заметка']),
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertNotNull($data['itemId']);
        $this->assertNotNull($data['itemNo']);

        $em->clear();
        /** @var WardrobeItem $item */
        $item = $em->getRepository(WardrobeItem::class)->find($data['itemId']);
        $this->assertNotNull($item);
        $this->assertSame('Изменённое имя', $item->getName());
        $this->assertSame(WardrobeItem::SOURCE_IMPORT, $item->getSource());
        $this->assertSame('M', $item->getSize());

        /** @var WardrobeItemDraft $receipt */
        $receipt = $em->getRepository(WardrobeItemDraft::class)->find($draftId);
        $this->assertNotNull($receipt);
        $this->assertSame(WardrobeItemDraft::STATUS_ACCEPTED, $receipt->getStatus());
        $this->assertSame($item->getId(), $receipt->getAcceptedItem()?->getId());

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/accept',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Повтор не должен изменить вещь', 'category' => 'Другое']),
        );

        $this->assertResponseIsSuccessful();
        $retry = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($data['itemId'], $retry['itemId']);
        $this->assertTrue($retry['idempotent']);
        $this->assertSame($itemCountBefore + 1, $em->getRepository(WardrobeItem::class)->count([]));
    }

    /**
     * Регресс: гонка за item_no во время accept — конкурентная вставка происходит
     * реально внутри flush() (событие prePersist), строго между вычислением
     * nextItemNo() и INSERT основной вещи, что и вызывает настоящий
     * UniqueConstraintViolationException. До фикса retry в promoteDraft() не
     * пере-резолвил Wardrobe после resetManager() → второй flush падал с
     * ORMInvalidArgumentException (detached/new entity) и отдавал 500.
     */
    public function testAcceptRetriesAfterItemNoCollisionAndKeepsExactlyOneDefaultWardrobe(): void
    {
        $client = static::createClient();
        // По умолчанию KernelBrowser ребутит kernel на каждый следующий request(),
        // из-за чего наш prePersist-листенер на EM (ниже) отвалится ко второму
        // запросу вместе со всем контейнером. Отключаем ребут — иначе гонку честно
        // не воспроизвести, а не потому что мы что-то мокаем.
        $client->disableReboot();
        $user   = $this->loginAsCustomer($client);
        $userId = $user->getId();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $draft = $this->makeDraft($em, $user, 'batch-collision-' . uniqid(), WardrobeItemDraft::STATUS_RECOGNIZED, [
            'category' => 'Футболки',
            'name'     => 'Гоночная футболка',
        ]);
        $draftId = $draft->getId();

        $listener = new class ($userId) {
            public bool $fired = false;

            public function __construct(private readonly int $userId) {}

            // Конкурентная вещь с тем же item_no, вставленная напрямую в БД ровно
            // в момент flush() основной вещи — честная симуляция гонки, а не мок.
            public function prePersist(PrePersistEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->fired || !$entity instanceof WardrobeItem) {
                    return;
                }
                $this->fired = true;
                $args->getObjectManager()->getConnection()->executeStatement(
                    'INSERT INTO wardrobe_item (user_id, item_no, completion_status, item_status, source, wear_status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $this->userId,
                        $entity->getItemNo(),
                        WardrobeItem::COMPLETION_DRAFT,
                        WardrobeItem::ITEM_ACTIVE,
                        WardrobeItem::SOURCE_IMPORT,
                        WardrobeItem::WEAR_ACTIVE,
                        (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                );
            }
        };
        $em->getEventManager()->addEventListener(Events::prePersist, $listener);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/accept',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Гоночная футболка', 'category' => 'Футболки']),
        );

        self::assertTrue($listener->fired, 'Листенер не сработал — тест не воспроизвёл гонку за item_no');
        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertNotNull($data['itemId']);

        /** @var EntityManagerInterface $freshEm */
        $freshEm = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var WardrobeItem $item */
        $item = $freshEm->getRepository(WardrobeItem::class)->find($data['itemId']);
        $this->assertNotNull($item);
        $this->assertNotNull($item->getWardrobe(), 'После ретрая вещь должна быть привязана к default-гардеробу пользователя');

        $this->assertSame(
            1,
            $freshEm->getRepository(Wardrobe::class)->count(['owner' => $userId, 'isDefault' => true]),
        );
    }

    public function testRejectDeletesDraftWithoutCreatingItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $draft = $this->makeDraft($em, $user, 'batch-reject-' . uniqid(), WardrobeItemDraft::STATUS_PENDING);
        $draftId = $draft->getId();
        $itemCountBefore = (int) $em->getRepository(WardrobeItem::class)->count([]);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/reject',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);

        $this->assertNull($em->getRepository(WardrobeItemDraft::class)->find($draftId));
        $this->assertSame($itemCountBefore, (int) $em->getRepository(WardrobeItem::class)->count([]));
    }

    public function testPendingDraftCannotBeAccepted(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $draft = $this->makeDraft($em, $user, 'batch-pending-'.uniqid(), WardrobeItemDraft::STATUS_PENDING, [
            'category' => 'Рубашки',
            'name' => 'Ещё распознаётся',
        ]);
        $itemsBefore = $em->getRepository(WardrobeItem::class)->count([]);

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/'.$draft->getId().'/accept',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame($itemsBefore, $em->getRepository(WardrobeItem::class)->count([]));
        $this->assertSame(WardrobeItemDraft::STATUS_PENDING, $em->find(WardrobeItemDraft::class, $draft->getId())?->getStatus());
    }

    public function testAcceptAndRejectOnForeignDraftReturn404(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $otherUser = UserFactory::brandOwner(static::getContainer());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $draft = $this->makeDraft($em, $otherUser, 'batch-foreign-' . uniqid(), WardrobeItemDraft::STATUS_RECOGNIZED, [
            'category' => 'Обувь',
            'name'     => 'Чужой черновик',
        ]);
        $draftId = $draft->getId();

        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/accept',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Угнанное имя']),
        );
        $this->assertResponseStatusCodeSame(404);

        $client->request(
            'POST',
            '/account/wardrobe/ingest/draft/' . $draftId . '/reject',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        $this->assertResponseStatusCodeSame(404);

        $em->clear();
        /** @var WardrobeItemDraft $reloaded */
        $reloaded = $em->getRepository(WardrobeItemDraft::class)->find($draftId);
        $this->assertNotNull($reloaded);
        $this->assertSame('Чужой черновик', $reloaded->getName());
    }

    public function testUploadRequiresExplicitPhotoConsent(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'ingest-explicit-consent-'.bin2hex(random_bytes(4)).'@test.local');
        $client->loginUser($user);
        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $client->request('POST', '/account/wardrobe/ingest/upload', [], [
            'photos' => [new UploadedFile($this->makeTempImage(), 'private.png', 'image/png', null, true)],
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('согласие', (string) json_decode((string) $client->getResponse()->getContent(), true)['error']);
        $this->assertSame(0, static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeConsent::class)->count(['subject' => $user]));
    }

    public function testChildCannotGrantOwnPhotoProcessingConsent(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'ingest-consent-parent-'.bin2hex(random_bytes(4)).'@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Аня');
        $client->loginUser($child);
        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $client->request('POST', '/account/wardrobe/ingest/upload', ['photoConsent' => '1'], [
            'photos' => [new UploadedFile($this->makeTempImage(), 'child.png', 'image/png', null, true)],
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(0, static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeConsent::class)->count(['subject' => $child]));
    }

    public function testImageSanitizerRemovesEmbeddedMetadata(): void
    {
        $path = $this->makeTempImage();
        file_put_contents($path, 'GPSSECRET', FILE_APPEND);
        $sanitized = static::getContainer()->get(WardrobeImageSanitizer::class)->sanitize(new UploadedFile($path, 'metadata.png', 'image/png', null, true));
        $this->tmpFiles[] = $sanitized->getPathname();

        $this->assertStringNotContainsString('GPSSECRET', (string) file_get_contents($sanitized->getPathname()));
        $this->assertSame('image/jpeg', $sanitized->getMimeType());
    }

    public function testDraftStorageQuotaBlocksNewUpload(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'ingest-storage-quota-'.bin2hex(random_bytes(4)).'@test.local');
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $consent = new WardrobeConsent($user, $user);
        $consent->grantPhotoProcessing($user);
        $draft = (new WardrobeItemDraft())->setUser($user)->setBatchId('quota-batch')->setFileSize(200_000_000)->setPhoto('quota.jpg');
        $em->persist($consent);
        $em->persist($draft);
        $em->flush();
        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $client->request('POST', '/account/wardrobe/ingest/upload', [], [
            'photos' => [new UploadedFile($this->makeTempImage(), 'over-quota.png', 'image/png', null, true)],
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('лимит хранения', (string) json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    /** @param array{category?:string,name?:string} $fields */
    private function makeDraft(
        EntityManagerInterface $em,
        User $user,
        string $batchId,
        string $status,
        array $fields = [],
    ): WardrobeItemDraft {
        $draft = new WardrobeItemDraft();
        $draft->setUser($user);
        $draft->setBatchId($batchId);
        $draft->setStatus($status);
        if (isset($fields['category'])) {
            $draft->setCategory($fields['category']);
        }
        if (isset($fields['name'])) {
            $draft->setName($fields['name']);
        }
        $em->persist($draft);
        $em->flush();

        return $draft;
    }

    private function makeTempImage(): string
    {
        $path = sys_get_temp_dir() . '/wardrobe_ingest_test_' . uniqid() . '.png';
        $im   = imagecreatetruecolor(4, 4);
        $color = imagecolorallocate($im, random_int(1, 255), random_int(1, 255), random_int(1, 255));
        imagefill($im, 0, 0, $color);
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function makeTempTextFile(): string
    {
        $path = sys_get_temp_dir() . '/wardrobe_ingest_test_' . uniqid() . '.txt';
        file_put_contents($path, 'not an image');
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * Форсирует CSRF-токен для роли, у которой в UI никогда не рендерится соответствующая
     * форма, но которая всё равно проходит через isCsrfTokenValid() в контроллере. Токен
     * генерируется в СЕССИИ последнего реального запроса клиента (тот же request, та же
     * cookie) — иначе он не совпадёт с тем, что проверит контроллер на следующем запросе.
     * Скопировано из WardrobeControllerTest (тот же паттерн для того же приложения).
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
