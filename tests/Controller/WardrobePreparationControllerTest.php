<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;

final class WardrobePreparationControllerTest extends AuthenticatedWebTestCase
{
    public function testAuditIsScopedAndListsOnlyActiveWearableItemsWithMissingFields(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'preparation-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Соня');
        $foreign = UserFactory::withEmail(static::getContainer(), 'preparation-foreign@test.local');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $missing = (new WardrobeItem())->setUser($child)->setItemNo(1)->setName('Нужен цвет')->setCategory('Рубашки')->setSeason('summer');
        $complete = (new WardrobeItem())->setUser($child)->setItemNo(2)->setName('Заполнена')->setCategory('Рубашки')->setSeason('summer')->setColorName('белый');
        $archived = (new WardrobeItem())->setUser($child)->setItemNo(3)->setName('Архив')->setItemStatus(WardrobeItem::ITEM_ARCHIVED);
        $outgrown = (new WardrobeItem())->setUser($child)->setItemNo(4)->setName('Мала')->setWearStatus(WardrobeItem::WEAR_OUTGROWN);
        $deleted = (new WardrobeItem())->setUser($child)->setItemNo(5)->setName('Удалена');
        $deleted->softDelete();
        $other = (new WardrobeItem())->setUser($foreign)->setItemNo(1)->setName('Чужая');
        foreach ([$missing, $complete, $archived, $outgrown, $deleted, $other] as $item) $em->persist($item);
        $em->flush();
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/preparation?member='.$child->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-preparation-item]'));
        self::assertSame((string) $missing->getId(), $crawler->filter('[data-preparation-item]')->attr('data-preparation-item'));
        self::assertStringContainsString('member='.$child->getId(), $crawler->selectLink('Проверить карточку')->attr('href'));
        $client->request('GET', '/account/wardrobe/preparation?member='.$foreign->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAuditUsesStablePaginationAndRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe/preparation');
        self::assertResponseRedirects('/login');
        $user = UserFactory::withEmail(static::getContainer(), 'preparation-pages@test.local');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 1; $i <= 31; $i++) $em->persist((new WardrobeItem())->setUser($user)->setItemNo($i));
        $em->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/wardrobe/preparation');
        self::assertResponseIsSuccessful();
        self::assertCount(30, $crawler->filter('[data-preparation-item]'));
        $crawler = $client->click($crawler->selectLink('Следующие вещи')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-preparation-item]'));
    }
}
