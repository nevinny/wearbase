<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class WardrobeClassificationControllerTest extends AuthenticatedWebTestCase
{
    /** @var WardrobeItem[] */
    private array $createdItems = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->createdItems as $item) {
                $this->em()->getConnection()->executeStatement('DELETE FROM wardrobe_item WHERE id = ?', [$item->getId()]);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('classificationSeasons')]
    public function testParentCanClassifyChildItemInlineWithoutChangingOtherFields(string $season, string $visibleSeason): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'classification-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Ребёнок');
        $item = $this->item($child)->setSize('128')->setNotes('Нужна новая молния')
            ->setColorName('белый')->setWearStatus(WardrobeItem::WEAR_RESERVE)->setItemStatus(WardrobeItem::ITEM_REPAIR);
        $category = $this->category();
        $this->em()->flush();
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/family/matrix?season=winter');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-matrix-unclassified] [data-classification-item="'.$item->getId().'"]'));
        self::assertCount(0, $crawler->filter('details [data-classification-item="'.$item->getId().'"]'));
        $name = 'wardrobe_classification_'.$item->getId();
        self::assertSame('', $crawler->filter('select[name="'.$name.'[season]"] option[selected]')->attr('value'));
        $client->submit($crawler->filter('form[name="'.$name.'"]')->form([
            $name.'[category]' => (string) $category->getId(), $name.'[season]' => $season,
        ]));
        self::assertResponseRedirects('/account/family/matrix?season='.$visibleSeason.'#matrix-'.$visibleSeason.'-'.$category->getId().'-'.$child->getId());
        $this->em()->clear();
        $saved = $this->em()->find(WardrobeItem::class, $item->getId());
        self::assertSame($category->getId(), $saved->getCategoryRef()->getId());
        self::assertSame($category->getName(), $saved->getCategory());
        self::assertSame($season, $saved->getSeason());
        self::assertSame('Неопределённая вещь', $saved->getName());
        self::assertSame('128', $saved->getSize());
        self::assertSame('Нужна новая молния', $saved->getNotes());
        self::assertSame('белый', $saved->getColorName());
        self::assertSame(WardrobeItem::WEAR_RESERVE, $saved->getWearStatus());
        self::assertSame(WardrobeItem::ITEM_REPAIR, $saved->getItemStatus());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-classification-item="'.$item->getId().'"]');
        self::assertSelectorTextContains('[data-matrix-cell][data-category="'.$category->getId().'"][data-member="'.$child->getId().'"]', 'Неопределённая вещь');
    }

    public static function classificationSeasons(): array
    {
        return [['winter', 'winter'], ['summer', 'summer'], ['all', 'winter']];
    }

    public function testUserWithoutFamilyCanClassifyTheirOwnItem(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'classification-own@test.local');
        $category = $this->category();
        $item = $this->item($user)->setCategoryRef($category);
        $this->em()->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/family/matrix');
        self::assertResponseIsSuccessful();
        $name = 'wardrobe_classification_'.$item->getId();
        self::assertSame((string) $category->getId(), $crawler->filter('select[name="'.$name.'[category]"] option[selected]')->attr('value'));
        $client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[season]' => 'all']));
        self::assertResponseRedirects('/account/family/matrix#matrix-winter-'.$category->getId().'-'.$user->getId());
        self::assertSame('all', $this->em()->find(WardrobeItem::class, $item->getId())->getSeason());
    }

    public function testInvalidSeasonAndInactiveCategoryKeepInputsVisibleWithoutSaving(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'classification-invalid@test.local');
        $category = $this->category();
        $inactive = (new WardrobeCategory())->setCode('classification-inactive')->setName('Старая категория')->setActive(false);
        $this->em()->persist($inactive);
        $item = $this->item($user);
        $this->em()->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/family/matrix?season=summer');
        $name = 'wardrobe_classification_'.$item->getId();
        self::assertCount(0, $crawler->filter('select[name="'.$name.'[category]"] option[value="'.$inactive->getId().'"]'));
        $token = $crawler->filter('input[name="'.$name.'[_token]"]')->attr('value');
        $client->request('POST', '/account/family/matrix/items/'.$item->getId().'/classify?season=summer', [$name => [
            'category' => (string) $inactive->getId(), 'season' => 'winter', '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-classification-item="'.$item->getId().'"] select[name="'.$name.'[season]"] option[value="winter"][selected]');
        $client->request('POST', '/account/family/matrix/items/'.$item->getId().'/classify?season=summer', [$name => [
            'category' => (string) $category->getId(), 'season' => 'moon', '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-classification-item="'.$item->getId().'"] select[name="'.$name.'[category]"] option[value="'.$category->getId().'"][selected]');
        $this->em()->clear();
        $saved = $this->em()->find(WardrobeItem::class, $item->getId());
        self::assertNull($saved->getCategoryRef());
        self::assertNull($saved->getSeason());
        self::assertSame('Неизвестная категория', $saved->getCategory());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testForgedCsrfTokenCannotClassifyOwnItem(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'classification-csrf@test.local');
        $item = $this->item($user);
        $this->em()->flush();
        $client->loginUser($user);
        $client->request('POST', '/account/family/matrix/items/'.$item->getId().'/classify', [
            'wardrobe_classification_'.$item->getId() => ['_token' => 'forged', 'season' => 'winter'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em()->find(WardrobeItem::class, $item->getId())->getSeason());
    }

    public function testForeignSpouseAndMovedChildItemsCannotBeClassified(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'classification-isolation-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Ребёнок');
        $spouse = UserFactory::withEmail(static::getContainer(), 'classification-spouse@test.local')
            ->setFamily($parent->getFamily())->setFamilyRole(User::FAMILY_ROLE_PARENT);
        $outsider = UserFactory::withEmail(static::getContainer(), 'classification-outsider@test.local');
        $childItem = $this->item($child);
        $spouseItem = $this->item($spouse);
        $this->em()->flush();
        $client->loginUser($outsider);
        $client->request('POST', '/account/family/matrix/items/'.$childItem->getId().'/classify');
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($parent);
        $client->request('POST', '/account/family/matrix/items/'.$spouseItem->getId().'/classify');
        self::assertResponseStatusCodeSame(403);
        $this->em()->find(User::class, $child->getId())->setFamily(null)->setFamilyRole(null);
        $this->em()->flush();
        $client->request('POST', '/account/family/matrix/items/'.$childItem->getId().'/classify');
        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('unavailableStates')]
    public function testUnavailableItemsCannotBeClassified(string $state): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'classification-unavailable-'.$state.'@test.local');
        $item = $this->item($user);
        match ($state) {
            'deleted' => $item->softDelete(),
            'given_away' => $item->setWearStatus(WardrobeItem::WEAR_GIVEN_AWAY),
            default => $item->setItemStatus($state),
        };
        $this->em()->flush();
        $client->loginUser($user);
        $client->request('POST', '/account/family/matrix/items/'.$item->getId().'/classify');
        self::assertResponseStatusCodeSame(404);
    }

    public static function unavailableStates(): array
    {
        return [['sold'], ['transferred'], ['given_away'], ['deleted']];
    }

    private function item(User $owner): WardrobeItem
    {
        $item = (new WardrobeItem())->setUser($owner)->setItemNo(1)
            ->setName('Неопределённая вещь')->setCategory('Неизвестная категория');
        $this->createdItems[] = $item;
        $this->em()->persist($item);
        return $item;
    }

    private function category(): WardrobeCategory
    {
        $category = $this->em()->getRepository(WardrobeCategory::class)->findOneBy(['code' => 'classification-shirt']);
        if ($category === null) {
            $category = (new WardrobeCategory())->setCode('classification-shirt')->setName('Рубашка для проверки');
            $this->em()->persist($category);
            $this->em()->flush();
        }
        return $category;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
