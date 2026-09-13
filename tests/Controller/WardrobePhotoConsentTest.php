<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeConsent;
use App\Service\Wardrobe\WardrobeAiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Модель согласия на фото привязана к адресату, а не к месту в интерфейсе:
 * обработка на оборудовании Оператора покрыта общим согласием при регистрации,
 * передача внешнему AI-сервису требует отдельной отметки субъекта.
 */
class WardrobePhotoConsentTest extends AuthenticatedWebTestCase
{
    private const CSRF_ID = 'wardrobe_ingest';

    /** @var string[] */
    private array $tmpFiles = [];

    private ?string $visionLocalBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
        $this->visionLocalBackup = $_SERVER['WARDROBE_VISION_LOCAL'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];
        if ($this->visionLocalBackup === null) {
            unset($_SERVER['WARDROBE_VISION_LOCAL'], $_ENV['WARDROBE_VISION_LOCAL']);
        } else {
            $_SERVER['WARDROBE_VISION_LOCAL'] = $_ENV['WARDROBE_VISION_LOCAL'] = $this->visionLocalBackup;
        }
        parent::tearDown();
    }

    /** Локальная обработка: третьей стороны нет — отдельной отметки не спрашиваем. */
    public function testLocalProcessingAcceptsUploadWithoutSeparateConsent(): void
    {
        $this->useLocalVision();
        $client = static::createClient();
        $user = $this->loginFresh($client);

        $this->upload($client, []);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->consentRows($user));
        // Явно фиксируем, что тест зелёный именно из-за локального режима, а не потому,
        // что переключение WARDROBE_VISION_LOCAL до контейнера не доехало.
        $this->assertFalse(static::getContainer()->get(WardrobeAiService::class)->externalPhotoConsentRequired($user));
    }

    /**
     * И не записываем согласие, даже если photoConsent=1 пришёл в запросе (старая
     * вкладка, отложенная очередь загрузки): отметка о согласии не должна появляться
     * там, где пользователя о нём не спрашивали.
     */
    public function testLocalProcessingDoesNotRecordSubmittedConsent(): void
    {
        $this->useLocalVision();
        $client = static::createClient();
        $user = $this->loginFresh($client);

        $this->upload($client, ['photoConsent' => '1']);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->consentRows($user));
    }

    /**
     * Отметки, проставленные до того, как текст согласия описал передачу наружу,
     * не покрывают её: у пользователя снова спрашивают подтверждение.
     */
    public function testConsentGivenBeforeDisclosureDoesNotCoverExternalTransfer(): void
    {
        $this->useRemoteVision();
        $client = static::createClient();
        $user = $this->loginFresh($client);
        $this->grantPhotoConsent($user, new \DateTimeImmutable(WardrobeConsent::PHOTO_TRANSFER_DISCLOSED_SINCE.' -1 day'));

        $this->upload($client, []);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('согласие', $this->errorOf($client));
    }

    public function testConsentGivenAfterDisclosureCoversExternalTransfer(): void
    {
        $this->useRemoteVision();
        $client = static::createClient();
        $user = $this->loginFresh($client);
        $this->grantPhotoConsent($user, new \DateTimeImmutable());

        $this->upload($client, []);

        $this->assertResponseIsSuccessful();
    }

    /**
     * Гейт живёт в точке отправки, а не в точке загрузки: фото, принятое при живом
     * согласии, не уйдёт наружу, если согласие отозвано до фоновой обработки
     * (тот же путь, которым идут app:wardrobe:ingest-drafts и telegram-бот).
     */
    public function testRevokedConsentStopsPhotoAtDispatchTime(): void
    {
        $this->useRemoteVision();
        $client = static::createClient();
        $user = $this->loginFresh($client);
        $this->grantPhotoConsent($user, new \DateTimeImmutable());

        $this->upload($client, []);
        $this->assertResponseIsSuccessful();

        // Перечитываем из текущего EM: после запроса ядро могло перезагрузиться,
        // и объект, созданный до него, уже не управляется этим менеджером.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(WardrobeConsent::class)->findOneBy(['subject' => $user])->revoke();
        $em->flush();

        /** @var WardrobeAiService $ai */
        $ai = static::getContainer()->get(WardrobeAiService::class);
        $result = $ai->suggestFromPhoto($this->makeTempImage(), $user);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('согласия', $result['error']);
    }

    /** Свой пользователь на тест: harness-customer общий, его согласия текли бы между тестами. */
    private function loginFresh(object $client): User
    {
        $user = UserFactory::withEmail(static::getContainer(), 'photo-consent-'.bin2hex(random_bytes(4)).'@test.local');
        $client->loginUser($user);

        return $user;
    }

    private function useLocalVision(): void
    {
        $_SERVER['WARDROBE_VISION_LOCAL'] = $_ENV['WARDROBE_VISION_LOCAL'] = '1';
    }

    private function useRemoteVision(): void
    {
        $_SERVER['WARDROBE_VISION_LOCAL'] = $_ENV['WARDROBE_VISION_LOCAL'] = '0';
    }

    /** @param array<string, string> $params */
    private function upload(object $client, array $params): void
    {
        $client->request('GET', '/account/wardrobe');
        $token = $this->forceCsrfToken($client->getRequest(), self::CSRF_ID);
        $client->request(
            'POST',
            '/account/wardrobe/ingest/upload',
            $params,
            ['photos' => [new UploadedFile($this->makeTempImage(), 'item.png', 'image/png', null, true)]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
    }

    private function grantPhotoConsent(User $user, \DateTimeImmutable $grantedAt): WardrobeConsent
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $consent = new WardrobeConsent($user, $user);
        $consent->grantPhotoProcessing($user);
        (new \ReflectionProperty(WardrobeConsent::class, 'photoProcessingGrantedAt'))->setValue($consent, $grantedAt);
        $em->persist($consent);
        $em->flush();

        return $consent;
    }

    private function consentRows(User $user): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(WardrobeConsent::class)->count(['subject' => $user]);
    }

    private function errorOf(object $client): string
    {
        return (string) json_decode((string) $client->getResponse()->getContent(), true)['error'];
    }

    private function makeTempImage(): string
    {
        $path = sys_get_temp_dir().'/wardrobe_consent_test_'.uniqid().'.png';
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(1, 255), random_int(1, 255), random_int(1, 255)));
        imagepng($image, $path);
        imagedestroy($image);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /** См. WardrobeIngestControllerTest::forceCsrfToken — тот же паттерн. */
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
