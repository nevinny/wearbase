<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BrandStyle;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeTransfer;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;

class WardrobeExportControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testJsonExportsOwnActiveItemsWithPhotosAndTransfers(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-export-parent@test.local');
        $parent->setFirstName('Анна');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Оля');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $style = (new BrandStyle())->setTitle('Классика')->setSlug('classic');
        $item = (new WardrobeItem())
            ->setUser($parent)
            ->setOriginalOwner($child)
            ->setItemNo(901)
            ->setName('Пальто')
            ->setCategory('Пальто')
            ->addStyle($style)
            ->setPrice('12500.00')
            ->setPhoto('legacy.jpg');
        $photo = (new WardrobeItemPhoto())
            ->setFilePath('gallery.jpg')
            ->setPhotoType(WardrobeItemPhoto::TYPE_DETAIL);
        $item->addPhoto($photo);
        $transfer = (new WardrobeTransfer())
            ->setItem($item)
            ->setFromUser($child)
            ->setToUser($parent)
            ->setActor($parent)
            ->setNote('стало впору');
        $em->persist($item);
        $em->persist($style);
        $em->persist($transfer);
        $em->flush();

        $client->request('GET', '/account/wardrobe/export/json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=UTF-8');
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('wearbase.wardrobe', $data['format']);
        self::assertSame(1, $data['version']);
        self::assertSame('Пальто', $data['items'][0]['name']);
        self::assertSame(['classic'], $data['items'][0]['styles']);
        self::assertSame('/images/wardrobe/le/ga/legacy.jpg', $data['items'][0]['photos'][0]['url']);
        self::assertSame('стало впору', $data['items'][0]['transfers'][0]['note']);
    }

    public function testArchiveOptionAndCsvExport(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'harness-export-csv@test.local');
        $client->loginUser($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $archived = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(902)
            ->setName('Архивная юбка')
            ->setItemStatus(WardrobeItem::ITEM_ARCHIVED);
        $em->persist($archived);
        $em->flush();

        $client->request('GET', '/account/wardrobe/export/json');
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $data['items']);

        $client->request('GET', '/account/wardrobe/export/csv?archive=1');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\xEF\xBB\xBF", (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Архивная юбка', (string) $client->getResponse()->getContent());
    }

    public function testParentCanExportFamilyAndStrangerCannotBeSelected(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-export-family@test.local');
        $client->loginUser($parent);

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Илья');
        $stranger = UserFactory::withEmail(static::getContainer(), 'harness-export-stranger@test.local');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist((new WardrobeItem())->setUser($parent)->setItemNo(903)->setName('Вещь родителя'));
        $em->persist((new WardrobeItem())->setUser($child)->setItemNo(1)->setName('Вещь ребёнка'));
        $em->flush();

        $client->request('GET', '/account/wardrobe/export/json?scope=family');
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $data['owners']);
        self::assertCount(2, $data['items']);

        $client->request('GET', '/account/wardrobe/export/json?member='.$stranger->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testChildCannotExportWholeFamily(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-export-child-parent@test.local');

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Миша');
        $client->loginUser($child);

        $client->request('GET', '/account/wardrobe/export/json?scope=family');

        self::assertResponseStatusCodeSame(403);
    }
}
