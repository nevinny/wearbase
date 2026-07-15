<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use Doctrine\ORM\EntityManagerInterface;
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
            [],
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
            [],
            ['photos' => [$badFile]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['uploaded']);
        $this->assertCount(1, $data['rejected']);
        $this->assertSame('note.txt', $data['rejected'][0]['name']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $drafts = $em->getRepository(WardrobeItemDraft::class)->findBy(['user' => $user, 'batchId' => $data['batch']]);
        $this->assertCount(0, $drafts);
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

        $this->assertNull($em->getRepository(WardrobeItemDraft::class)->find($draftId));
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

    /**
     * @param array{category?:string,name?:string} $fields
     */
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
