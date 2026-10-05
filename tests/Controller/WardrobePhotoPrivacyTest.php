<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Entity\WardrobeWearEvent;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeWearRecognitionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

final class WardrobePhotoPrivacyTest extends WebTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach (array_unique($this->files) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('uploadProvider')]
    public function testChildUploadsStripMetadataAndMediaNeverReturnsRawOriginal(string $kind): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $parent = UserFactory::withEmail(static::getContainer(), 'photo-privacy-'.$kind.'@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Privacy child');
        $client->loginUser($parent);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fixture = __DIR__.'/../Fixtures/wardrobe-gps.jpg';
        self::assertArrayHasKey('GPSLatitude', exif_read_data($fixture));
        $input = tempnam(sys_get_temp_dir(), 'wardrobe_gps_');
        $this->files[] = $input;
        copy($fixture, $input);
        $upload = new UploadedFile($input, 'photo.jpg', 'image/jpeg', null, true);
        $member = '?member='.$child->getId();

        if ($kind === 'item' || $kind === 'gallery') {
            $crawler = $client->request('GET', '/account/wardrobe/new'.$member);
            $form = $crawler->selectButton('Сохранить')->form(['wardrobe_item_form[name]' => 'Privacy photo']);
            $client->request('POST', '/account/wardrobe/new'.$member, $form->getPhpValues(), [
                'wardrobe_item_form' => $kind === 'item' ? ['photoFile' => ['file' => $upload]] : ['galleryPhotos' => [$upload]],
            ]);
            self::assertResponseRedirects();
            $item = $em->getRepository(WardrobeItem::class)->findOneBy(['user' => $child]);
            self::assertNotNull($item);
            $entity = $kind === 'gallery' ? $item->getCoverPhoto() : $item;
            $field = $kind === 'gallery' ? 'file' : 'photoFile';
            $urls = ['/account/wardrobe/media/item/'.$item->getId()];
            if ($item->getCoverPhoto() !== null) {
                $urls[] = '/account/wardrobe/media/photo/'.$item->getCoverPhoto()->getId();
            }
        } elseif ($kind === 'draft') {
            $client->request('GET', '/account/wardrobe'.$member);
            $request = $client->getRequest();
            $stack = static::getContainer()->get('request_stack');
            $stack->push($request);
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken('wardrobe_ingest')->getValue();
            $stack->pop();
            $request->getSession()->save();
            $client->request('POST', '/account/wardrobe/ingest/upload'.$member, ['photoConsent' => '1'], [
                'photos' => [$upload],
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseIsSuccessful();
            $entity = $em->getRepository(WardrobeItemDraft::class)->findOneBy(['user' => $child]);
            $field = 'photoFile';
            $urls = ['/account/wardrobe/media/draft/'.$entity->getId()];
        } else {
            $recognition = $this->createMock(WardrobeWearRecognitionService::class);
            $recognition->expects(self::once())->method('candidates')->willReturn([]);
            static::getContainer()->set(WardrobeWearRecognitionService::class, $recognition);
            $crawler = $client->request('GET', '/account/wardrobe/wear'.$member);
            $client->request('POST', '/account/wardrobe/wear'.$member, [
                '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
                'photoConsent' => '1',
            ], ['photo' => $upload]);
            self::assertResponseRedirects();
            $entity = $em->getRepository(WardrobeWearEvent::class)->findOneBy(['profileSubject' => $child]);
            $field = 'photoFile';
            $urls = ['/account/wardrobe/media/wear/'.$entity->getId()];
        }

        $stored = static::getContainer()->get(StorageInterface::class)->resolvePath($entity, $field);
        self::assertNotNull($stored);
        $this->files[] = $stored;
        self::assertArrayNotHasKey('GPSLatitude', @exif_read_data($stored) ?: []);
        self::assertArrayNotHasKey('Orientation', @exif_read_data($stored) ?: []);
        self::assertStringNotContainsString('PRIVATE-GPS', file_get_contents($stored));
        self::assertSame([40, 80], array_slice(getimagesize($stored), 0, 2));

        // Simulate an older unsanitized upload: even size=original must return a safe derivative.
        copy($fixture, $stored);
        foreach ($urls as $url) {
            foreach (['preview', 'medium', 'original'] as $size) {
                $client->request('GET', $url.'?size='.$size);
                self::assertResponseIsSuccessful();
                self::assertResponseHeaderSame('Content-Type', 'image/webp');
                $response = $client->getResponse();
                self::assertInstanceOf(BinaryFileResponse::class, $response);
                $path = $response->getFile()->getPathname();
                $this->files[] = $path;
                self::assertNotSame($stored, $path);
                self::assertArrayNotHasKey('GPSLatitude', @exif_read_data($path) ?: []);
                self::assertStringNotContainsString('PRIVATE-GPS', file_get_contents($path));
                self::assertSame([40, 80], array_slice(getimagesize($path), 0, 2));
            }
        }
    }

    public static function uploadProvider(): array
    {
        return ['item form' => ['item'], 'gallery' => ['gallery'], 'draft batch' => ['draft'], 'wear photo' => ['wear']];
    }
}
