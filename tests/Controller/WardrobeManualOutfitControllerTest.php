<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeManualOutfit;
use Doctrine\ORM\EntityManagerInterface;

final class WardrobeManualOutfitControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testCreatesAndUpdatesManualOutfitFromOwnedItems(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $first = (new WardrobeItem())->setUser($user)->setItemNo(8101)->setName('Жакет');
        $second = (new WardrobeItem())->setUser($user)->setItemNo(8102)->setName('Брюки');
        $em->persist($first); $em->persist($second); $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/outfits/manual');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $layout = [
            ['itemId' => $first->getId(), 'x' => 10, 'y' => 12, 'width' => 40, 'rotation' => -5, 'z' => 1],
            ['itemId' => $second->getId(), 'x' => 45, 'y' => 48, 'width' => 35, 'rotation' => 3, 'z' => 2],
        ];
        $client->request('POST', '/account/wardrobe/outfits/manual/save', [
            '_token' => $token, 'member' => $user->getId(), 'title' => 'Офисный образ',
            'layout' => json_encode($layout, JSON_THROW_ON_ERROR),
        ]);

        $outfit = $em->getRepository(WardrobeManualOutfit::class)->findOneBy(['wardrobeOwner' => $user]);
        self::assertNotNull($outfit);
        self::assertSame('Офисный образ', $outfit->getTitle());
        self::assertCount(2, $outfit->getItems());
        self::assertCount(2, $outfit->getLayout());
        $this->assertResponseRedirects('/account/wardrobe/outfits/manual?member='.$user->getId().'&edit='.$outfit->getId());
    }

    public function testCannotPutAnotherUsersItemIntoOutfit(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $own = (new WardrobeItem())->setUser($user)->setItemNo(8201);
        $foreignUser = UserFactory::brandOwner(static::getContainer());
        $foreign = (new WardrobeItem())->setUser($foreignUser)->setItemNo(8201);
        $em->persist($own); $em->persist($foreign); $em->flush();
        $before = $em->getRepository(WardrobeManualOutfit::class)->count([]);
        $crawler = $client->request('GET', '/account/wardrobe/outfits/manual');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/wardrobe/outfits/manual/save', [
            '_token' => $token,
            'layout' => json_encode([
                ['itemId' => $own->getId(), 'x' => 0, 'y' => 0],
                ['itemId' => $foreign->getId(), 'x' => 20, 'y' => 20],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertResponseRedirects('/account/wardrobe/outfits/manual?member='.$user->getId());
        self::assertSame($before, $em->getRepository(WardrobeManualOutfit::class)->count([]));
    }
}
