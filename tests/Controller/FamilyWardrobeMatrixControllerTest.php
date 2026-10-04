<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FittingFeedback;
use App\Entity\PurchaseRequest;
use App\Entity\User;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeNeed;
use App\Service\Family\FamilyWardrobeMatrix;
use App\Service\Family\WardrobeNeedService;
use App\Service\FamilyLifecycleService;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use App\Service\Wardrobe\PurchaseToWardrobeService;
use Doctrine\ORM\EntityManagerInterface;

final class FamilyWardrobeMatrixControllerTest extends AuthenticatedWebTestCase
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
            if ($this->createdItems !== []) {
                $connection = $this->em()->getConnection();
                foreach ($this->createdItems as $item) {
                    if ($item->getId() === null) {
                        continue;
                    }
                    $connection->executeStatement('DELETE FROM wardrobe_transfer WHERE item_id = ?', [$item->getId()]);
                    $connection->executeStatement('UPDATE purchase_request_item SET wardrobe_item_id = NULL WHERE wardrobe_item_id = ?', [$item->getId()]);
                    $connection->executeStatement('DELETE FROM wardrobe_item WHERE id = ?', [$item->getId()]);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testGuestCannotReadMatrixOrCreateNeed(): void
    {
        $client = static::createClient();
        foreach (['/account/family/matrix', '/account/family/matrix/needs/new'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/login');
        }
    }

    public function testChildAndUserWithoutFamilyCannotManageMatrix(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('access');
        $unrelated = UserFactory::withEmail(static::getContainer(), 'matrix-no-family@test.local');
        foreach ([$child, $unrelated] as $actor) {
            $client->loginUser($actor);
            $client->request('GET', '/account/family/matrix');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/account/family/matrix/needs/new');
            self::assertResponseStatusCodeSame(403);
        }
        $client->loginUser($parent);
        $client->request('GET', '/account/family/matrix?season=invalid');
        self::assertResponseStatusCodeSame(400);
    }

    public function testOverviewGroupsLegacyItemsAndRepeatsAllSeasonWithoutDuplicatingTotals(): void
    {
        [$parent, $child] = $this->family('grouping');
        $sibling = $this->families()->createChild($parent, 'Младший');
        $tops = $this->category('tops', 'Верх');
        $shirtCategory = $this->category('matrix-shirt', 'Футболка матрицы', $tops);
        $accessories = $this->category('accessories', 'Аксессуары');
        $hatCategory = $this->category('hat', 'Шапка', $accessories);
        $scarfCategory = $this->category('scarf', 'Шарф', $accessories);
        $dressCategory = $this->category('dresses', 'Платья');
        $shirt = $this->item($child, 1, 'Всесезонная футболка', $shirtCategory, 'all');
        $hat = $this->item($child, 2, 'Зимняя шапка', $hatCategory);
        $scarf = $this->item($child, 3, 'Шарф из старого импорта', null)->setCategory($scarfCategory->getName());
        $dress = $this->item($sibling, 1, 'Летнее платье', $dressCategory, 'summer');
        $unknownCategory = $this->item($child, 4, 'Неразобранная вещь', null)->setCategory('Неизвестный тип');
        $unknownSeason = $this->item($child, 5, 'Нужно уточнить сезон', $shirtCategory, null);
        $this->item($parent, 1, 'Вещь родителя', $shirtCategory);
        $this->em()->flush();

        $matrix = $this->matrix()->overview($parent);
        self::assertSame(5, $matrix['totals'][$child->getId()]);
        self::assertSame(1, $matrix['totals'][$sibling->getId()]);
        self::assertCount(2, $matrix['children']);
        foreach (['winter', 'spring', 'summer', 'autumn'] as $season) {
            self::assertSame([$shirt->getId()], $this->ids($matrix['sections'][$season]['groups']['tops'][$shirtCategory->getId()]['cells'][$child->getId()]['items']));
        }
        $winter = $matrix['sections']['winter']['groups'];
        self::assertSame([$hat->getId()], $this->ids($winter['headwear'][$hatCategory->getId()]['cells'][$child->getId()]['items']));
        self::assertSame([$scarf->getId()], $this->ids($winter['headwear'][$scarfCategory->getId()]['cells'][$child->getId()]['items']));
        self::assertSame([$dress->getId()], $this->ids($matrix['sections']['summer']['groups']['onepiece'][$dressCategory->getId()]['cells'][$sibling->getId()]['items']));
        self::assertEqualsCanonicalizing([$unknownCategory->getId(), $unknownSeason->getId()], $this->ids($matrix['uncategorized']));

        $winterOnly = $this->matrix()->overview($parent, 'winter');
        self::assertSame(['winter'], array_keys($winterOnly['sections']));
        self::assertSame($matrix['totals'], $winterOnly['totals']);
        self::assertCount(2, $winterOnly['uncategorized']);
    }

    public function testStatusesRemainDistinctAndUnavailableItemsAreExcluded(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('statuses');
        $category = $this->category('tops', 'Верх');
        $active = $this->item($child, 1, 'Можно носить', $category);
        $reserve = $this->item($child, 2, 'На вырост', $category)->setWearStatus(WardrobeItem::WEAR_RESERVE);
        $outgrown = $this->item($child, 3, 'Уже мала', $category)->setWearStatus(WardrobeItem::WEAR_OUTGROWN);
        $repair = $this->item($child, 4, 'Починить молнию', $category)->setItemStatus(WardrobeItem::ITEM_REPAIR);
        foreach ([...WardrobeItem::ARCHIVE_STATUSES, WardrobeItem::ITEM_TRANSFERRED] as $index => $status) {
            $this->item($child, 10 + $index, 'Исключённая вещь '.$status, $category)->setItemStatus($status);
        }
        $this->item($child, 30, 'Отданная вещь', $category)->setWearStatus(WardrobeItem::WEAR_GIVEN_AWAY);
        $this->item($child, 31, 'Удалённая вещь', $category)->softDelete();
        $this->em()->flush();

        $matrix = $this->matrix()->overview($parent, 'winter');
        $items = $matrix['sections']['winter']['groups']['tops'][$category->getId()]['cells'][$child->getId()]['items'];
        self::assertEqualsCanonicalizing($this->ids([$active, $reserve, $outgrown, $repair]), $this->ids($items));
        self::assertSame(4, $matrix['totals'][$child->getId()]);

        $client->loginUser($parent);
        $client->request('GET', '/account/family/matrix?season=winter');
        self::assertResponseIsSuccessful();
        foreach ([$reserve, $outgrown] as $item) {
            self::assertSelectorTextContains('body', $item->getWearStatusLabel());
        }
        self::assertSelectorTextContains('body', $repair->getItemStatusLabel());
        self::assertSelectorTextNotContains('body', 'Исключённая вещь');
        self::assertSelectorTextNotContains('body', 'Удалённая вещь');
    }

    public function testParentCreatesEditsClosesAndReopensNeed(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('crud');
        $category = $this->category('footwear', 'Обувь');
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/family/matrix/needs/new?season=winter');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form[name="wardrobe_need"]')->form([
            'wardrobe_need[subject]' => '0',
            'wardrobe_need[category]' => (string) $category->getId(),
            'wardrobe_need[season]' => 'winter',
            'wardrobe_need[title]' => 'Зимние ботинки',
            'wardrobe_need[quantity]' => '2',
            'wardrobe_need[size]' => '31',
            'wardrobe_need[notes]' => 'Для школы',
        ]));
        self::assertResponseRedirects('/account/family/matrix?season=winter');
        $need = $this->em()->getRepository(WardrobeNeed::class)->findOneBy(['subject' => $child]);
        self::assertNotNull($need);
        $needId = $need->getId();
        self::assertSame($parent->getFamily()->getId(), $need->getFamily()->getId());
        self::assertSame('31', $need->getSize());
        self::assertSame(2, $need->getQuantity());
        self::assertTrue($need->isOpen());

        $crawler = $client->request('GET', '/account/family/matrix/needs/'.$needId.'/edit');
        $client->submit($crawler->filter('form[name="wardrobe_need"]')->form([
            'wardrobe_need[title]' => 'Ботинки с утеплителем',
            'wardrobe_need[quantity]' => '1',
            'wardrobe_need[size]' => '32',
        ]));
        self::assertResponseRedirects();
        $need = $this->em()->find(WardrobeNeed::class, $needId);
        self::assertSame('Ботинки с утеплителем', $need->getTitle());
        self::assertSame('32', $need->getSize());
        self::assertSame(1, $need->getQuantity());

        foreach (['close' => false, 'reopen' => true] as $action => $open) {
            $crawler = $client->request('GET', '/account/family/matrix');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[action*="/account/family/matrix/needs/'.$needId.'/status"]')->first()->form();
            $client->submit($form, ['action' => $action]);
            self::assertResponseRedirects();
            self::assertSame($open, $this->em()->find(WardrobeNeed::class, $needId)->isOpen());
        }
    }

    public function testNeedFormRejectsInvalidCsrfAndQuantity(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('invalid-form');
        $category = $this->category('footwear', 'Обувь');
        $client->loginUser($parent);
        $data = ['subject' => '0', 'category' => (string) $category->getId(), 'season' => 'winter',
            'title' => 'Ботинки', 'quantity' => '1', 'size' => '', 'notes' => ''];
        $client->request('POST', '/account/family/matrix/needs/new', ['wardrobe_need' => $data + ['_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(WardrobeNeed::class)->count(['subject' => $child]));

        $crawler = $client->request('GET', '/account/family/matrix/needs/new');
        $data['_token'] = $crawler->filter('input[name="wardrobe_need[_token]"]')->attr('value');
        $data['quantity'] = '0';
        $client->request('POST', '/account/family/matrix/needs/new', ['wardrobe_need' => $data]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(WardrobeNeed::class)->count(['subject' => $child]));

        $data['quantity'] = '1';
        $data['subject'] = '999999';
        $client->request('POST', '/account/family/matrix/needs/new', ['wardrobe_need' => $data]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(WardrobeNeed::class)->count(['family' => $parent->getFamily()]));
    }

    public function testInvalidStatusCsrfDoesNotCloseNeed(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('status-csrf');
        $need = $this->need($parent, $child);
        $client->loginUser($parent);
        $client->request('POST', '/account/family/matrix/needs/'.$need->getId().'/status', ['_token' => 'invalid', 'action' => 'close']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->em()->find(WardrobeNeed::class, $need->getId())->isOpen());
    }

    public function testNeedsAreManualAndSeasonFilteringKeepsAllSeasonNeeds(): void
    {
        [$parent, $child] = $this->family('manual-needs');
        $category = $this->category('footwear', 'Обувь');
        $this->item($child, 1, 'Есть ботинки', $category);
        $winterNeed = $this->need($parent, $child);
        $allNeed = $this->need($parent, $child, 'all');
        $summerNeed = $this->need($parent, $child, 'summer');
        $closedNeed = $this->need($parent, $child);
        static::getContainer()->get(WardrobeNeedService::class)->setOpen($parent, $closedNeed, false);

        $matrix = $this->matrix()->overview($parent, 'winter');
        self::assertEqualsCanonicalizing([$winterNeed->getId(), $allNeed->getId()], $this->ids($matrix['openNeeds']));
        self::assertSame([$closedNeed->getId()], $this->ids($matrix['closedNeeds']));
        $cell = $matrix['sections']['winter']['groups']['footwear'][$category->getId()]['cells'][$child->getId()];
        self::assertCount(1, $cell['items']);
        self::assertCount(2, $cell['needs']);
        self::assertTrue($summerNeed->isOpen());
    }

    public function testOtherFamilyAndChildCannotReadChangeOrPurchaseNeed(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('private');
        [$outsider] = $this->family('private-outsider');
        $need = $this->need($parent, $child);
        foreach ([$outsider, $child] as $actor) {
            $client->loginUser($actor);
            $client->request('GET', '/account/family/matrix/needs/'.$need->getId().'/edit');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/account/family/matrix/needs/'.$need->getId().'/status', ['_token' => 'invalid', 'action' => 'close']);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/account/purchases/new?need='.$need->getId());
            self::assertResponseStatusCodeSame(403);
        }
        $client->loginUser($outsider);
        $client->request('GET', '/account/family/matrix');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $need->getTitle());
        self::assertTrue($this->em()->find(WardrobeNeed::class, $need->getId())->isOpen());
    }

    public function testSecondParentCanManageSharedFamilyNeeds(): void
    {
        $client = static::createClient();
        [$owner, $child] = $this->family('second-parent');
        $parent = UserFactory::withEmail(static::getContainer(), 'matrix-second-parent-spouse@test.local');
        $this->families()->acceptInvite($parent, $this->families()->createInvite($owner, User::FAMILY_ROLE_PARENT, $parent->getEmail()));
        $need = $this->need($owner, $child);
        $client->loginUser($parent);
        $client->request('GET', '/account/family/matrix');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $need->getTitle());

        $crawler = $client->request('GET', '/account/family/matrix/needs/'.$need->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form[name="wardrobe_need"]')->form([
            'wardrobe_need[notes]' => 'Уточнил второй родитель',
        ]));
        self::assertResponseRedirects();
        self::assertSame('Уточнил второй родитель', $this->em()->find(WardrobeNeed::class, $need->getId())->getNotes());
        $client->request('GET', '/account/purchases/new?need='.$need->getId());
        self::assertResponseIsSuccessful();
    }

    public function testInactiveCategoryKeepsExistingItemsButCannotBeChosenForNewNeed(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('inactive-category');
        $root = $this->category('footwear', 'Обувь');
        $retired = $this->category('matrix-retired-shoes', 'Старая категория обуви', $root)->setActive(false);
        $item = $this->item($child, 1, 'Ботинки из старой категории', $retired);
        $this->em()->flush();
        $matrix = $this->matrix()->overview($parent, 'winter');
        self::assertSame([$item->getId()], $this->ids($matrix['sections']['winter']['groups']['footwear'][$retired->getId()]['cells'][$child->getId()]['items']));
        self::assertSame(1, $matrix['totals'][$child->getId()]);

        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/family/matrix/needs/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="wardrobe_need[category]"] option[value="'.$retired->getId().'"]'));
        $client->request('POST', '/account/family/matrix/needs/new', ['wardrobe_need' => [
            'subject' => '0', 'category' => (string) $retired->getId(), 'season' => 'winter',
            'title' => 'Ботинки', 'quantity' => '1',
            '_token' => $crawler->filter('input[name="wardrobe_need[_token]"]')->attr('value'),
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(WardrobeNeed::class)->count(['subject' => $child]));
    }

    public function testNeedDoesNotFollowChildIntoAnotherFamily(): void
    {
        $client = static::createClient();
        [$oldParent, $child] = $this->family('moving');
        [$newParent] = $this->family('moving-new');
        $need = $this->need($oldParent, $child);
        static::getContainer()->get(FamilyLifecycleService::class)->removeMember($oldParent, $child);
        self::assertEmpty($this->matrix()->overview($oldParent)['openNeeds']);
        $invite = $this->families()->createInvite($newParent, User::FAMILY_ROLE_CHILD, $child->getEmail());
        $this->families()->acceptInvite($child, $invite);

        self::assertSame($oldParent->getFamily()->getId(), $need->getFamily()->getId());
        self::assertEmpty($this->matrix()->overview($newParent)['openNeeds']);
        foreach ([$oldParent, $newParent] as $actor) {
            $client->loginUser($actor);
            $client->request('GET', '/account/family/matrix/needs/'.$need->getId().'/edit');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testNeedPreselectsPurchaseAndRemainsOpenAfterRequestIsCreated(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('purchase');
        $this->families()->createChild($parent, 'Другой ребёнок');
        $need = $this->need($parent, $child);
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/purchases/new?need='.$need->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="purchase_request_form[subject]"] option'));
        self::assertStringContainsString($need->getTitle(), $crawler->filter('textarea[name="purchase_request_form[comment]"]')->text());
        self::assertStringContainsString($need->getSize(), $crawler->filter('textarea[name="purchase_request_form[comment]"]')->text());
        $client->submit($crawler->filter('form[name="purchase_request_form"]')->form([
            'purchase_request_form[productUrl]' => 'https://shop.example.test/matrix-boots',
        ]));
        $need = $this->em()->find(WardrobeNeed::class, $need->getId());
        $purchase = $need->getPurchaseRequest();
        self::assertInstanceOf(PurchaseRequest::class, $purchase);
        self::assertResponseRedirects('/account/purchases/'.$purchase->getId());
        self::assertSame($child->getId(), $purchase->getSubject()->getId());
        self::assertSame($parent->getFamily()->getId(), $purchase->getFamily()->getId());
        self::assertTrue($need->isOpen());

        $client->request('GET', '/account/purchases/new?need='.$need->getId());
        self::assertResponseRedirects('/account/purchases/'.$purchase->getId());
        self::assertSame(1, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));

        $need = $this->em()->find(WardrobeNeed::class, $need->getId());
        $parent = $this->em()->find(User::class, $parent->getId());
        $duplicate = static::getContainer()->get(WardrobeNeedService::class)->purchase($parent, $need, [
            'subject' => $need->getSubject(), 'productUrl' => 'https://shop.example.test/different-boots',
            'comment' => null, 'estimatedPrice' => null,
        ], static::getContainer()->get(PurchaseRequestService::class));
        self::assertSame($purchase->getId(), $duplicate->getId());
        self::assertSame(1, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
    }

    public function testPurchaseDomainErrorPreservesNeedAndEntityManager(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('purchase-error');
        $need = $this->need($parent, $child);
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/purchases/new?need='.$need->getId());
        $client->submit($crawler->filter('form[name="purchase_request_form"]')->form([
            'purchase_request_form[productUrl]' => 'https://127.0.0.1/product',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Допустима только безопасная HTTPS-ссылка');
        self::assertTrue($this->em()->isOpen());
        self::assertFalse($this->em()->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
        $this->em()->clear();
        $need = $this->em()->find(WardrobeNeed::class, $need->getId());
        self::assertNull($need->getPurchaseRequest());
        self::assertTrue($need->isOpen());

        $client->submit($client->getCrawler()->filter('form[name="purchase_request_form"]')->form([
            'purchase_request_form[productUrl]' => 'https://shop.example.test/retry-boots',
        ]));
        self::assertResponseRedirects();
        self::assertNotNull($this->em()->find(WardrobeNeed::class, $need->getId())->getPurchaseRequest());
    }

    public function testNeedPurchaseRejectsAdditionalProductsAndSharedCart(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('single-purchase');
        $need = $this->need($parent, $child);
        $client->loginUser($parent);
        foreach ([
            ['additionalUrls' => 'https://shop.example.test/unrelated-shirt'],
            ['importMode' => 'shared_cart'],
        ] as $extra) {
            $crawler = $client->request('GET', '/account/purchases/new?need='.$need->getId());
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[name="purchase_request_form[additionalUrls]"], [name="purchase_request_form[importMode]"]'));
            $client->request('POST', '/account/purchases/new?need='.$need->getId(), ['purchase_request_form' => $extra + [
                'subject' => '0', 'productUrl' => 'https://shop.example.test/boots',
                '_token' => $crawler->filter('input[name="purchase_request_form[_token]"]')->attr('value'),
            ]]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
            self::assertNull($this->em()->find(WardrobeNeed::class, $need->getId())->getPurchaseRequest());

            $parent = $this->em()->find(User::class, $parent->getId());
            $need = $this->em()->find(WardrobeNeed::class, $need->getId());
            try {
                static::getContainer()->get(WardrobeNeedService::class)->purchase($parent, $need, $extra + [
                    'subject' => $need->getSubject(), 'productUrl' => 'https://shop.example.test/boots',
                    'comment' => null, 'estimatedPrice' => null,
                ], static::getContainer()->get(PurchaseRequestService::class));
                self::fail('Additional products must also be rejected outside the form');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Для потребности укажите ссылку на один товар', $exception->getMessage());
            }
            self::assertTrue($this->em()->isOpen());
            self::assertFalse($this->em()->getConnection()->isTransactionActive());
            self::assertSame(0, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
            self::assertNull($need->getPurchaseRequest());
        }
    }

    public function testBoughtNeedEntersMatchingMatrixCellAndStillRequiresManualClose(): void
    {
        [$parent, $child] = $this->family('purchase-to-matrix');
        $need = $this->need($parent, $child);
        $needs = static::getContainer()->get(WardrobeNeedService::class);
        $purchases = static::getContainer()->get(PurchaseRequestService::class);
        $request = $needs->purchase($parent, $need, [
            'subject' => $child, 'productUrl' => 'https://shop.example.test/boots-for-matrix',
            'comment' => null, 'estimatedPrice' => '2100',
        ], $purchases);
        $position = $request->getItems()->first();
        $purchases->decideItem($parent, $request, $position, PurchaseRequest::STATUS_APPROVED);
        $purchases->markOrdered($parent, $request, $position, '1999');
        $purchases->markDelivered($parent, $request, $position);
        $purchases->recordFitting($child, $request, $position, FittingFeedback::OUTCOME_BOUGHT, '32', FittingFeedback::SIZING_TRUE, [], null);
        $item = static::getContainer()->get(PurchaseToWardrobeService::class)->add($parent, $request, $position);
        $this->createdItems[] = $item;
        $this->em()->clear();
        $item = $this->em()->find(WardrobeItem::class, $item->getId());
        $need = $this->em()->find(WardrobeNeed::class, $need->getId());
        $parent = $this->em()->find(User::class, $parent->getId());
        self::assertSame($need->getTitle(), $item->getName());
        self::assertSame($need->getCategory()->getId(), $item->getCategoryRef()->getId());
        self::assertSame($need->getSeason(), $item->getSeason());
        self::assertSame('32', $item->getSize());
        self::assertSame('31', $need->getSize());
        self::assertTrue($need->isOpen());

        $matrix = $this->matrix()->overview($parent, 'winter');
        $cell = $matrix['sections']['winter']['groups']['footwear'][$need->getCategory()->getId()]['cells'][$child->getId()];
        self::assertSame([$item->getId()], $this->ids($cell['items']));
        self::assertSame([$need->getId()], $this->ids($cell['needs']));
        self::assertEmpty($matrix['uncategorized']);
        $needs->setOpen($parent, $need, false);
        $matrix = $this->matrix()->overview($parent, 'winter');
        self::assertEmpty($matrix['openNeeds']);
        self::assertSame([$need->getId()], $this->ids($matrix['closedNeeds']));
    }

    public function testTransferMovesItemToCurrentChildColumn(): void
    {
        $client = static::createClient();
        [$parent, $child] = $this->family('transfer');
        $sibling = $this->families()->createChild($parent, 'Получатель');
        $category = $this->category('tops', 'Верх');
        $item = $this->item($child, 1, 'Передаваемая куртка', $category)->setOriginalOwner($child);
        $this->em()->flush();
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/'.$item->getId().'?member='.$child->getId());
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form[action*="/'.$item->getId().'/transfer"]')->form([
            'to_user' => (string) $sibling->getId(),
        ]));
        self::assertResponseRedirects();

        $parent = $this->em()->find(User::class, $parent->getId());
        $matrix = $this->matrix()->overview($parent, 'winter');
        self::assertSame(0, $matrix['totals'][$child->getId()]);
        self::assertSame(1, $matrix['totals'][$sibling->getId()]);
        $cells = $matrix['sections']['winter']['groups']['tops'][$category->getId()]['cells'];
        self::assertArrayNotHasKey($child->getId(), $cells);
        self::assertSame([$item->getId()], $this->ids($cells[$sibling->getId()]['items']));
        self::assertSame($child->getId(), $this->em()->find(WardrobeItem::class, $item->getId())->getOriginalOwner()->getId());
    }

    /** @return array{User, User} */
    private function family(string $suffix): array
    {
        $parent = UserFactory::withEmail(static::getContainer(), 'matrix-'.$suffix.'@test.local');
        return [$parent, $this->families()->createChild($parent, 'Ребёнок '.$suffix)];
    }

    private function category(string $code, string $name, ?WardrobeCategory $parent = null): WardrobeCategory
    {
        $category = $this->em()->getRepository(WardrobeCategory::class)->findOneBy(['code' => $code]);
        if ($category === null) {
            $category = (new WardrobeCategory())->setCode($code)->setName($name)->setParent($parent);
            $this->em()->persist($category);
            $this->em()->flush();
        }
        return $category;
    }

    private function item(User $child, int $number, string $name, ?WardrobeCategory $category, ?string $season = 'winter'): WardrobeItem
    {
        $item = (new WardrobeItem())->setUser($child)->setItemNo($number)->setName($name)->setCategoryRef($category)->setSeason($season);
        $this->createdItems[] = $item;
        $this->em()->persist($item);
        return $item;
    }

    private function need(User $parent, User $child, string $season = 'winter'): WardrobeNeed
    {
        return static::getContainer()->get(WardrobeNeedService::class)->save($parent, [
            'subject' => $child, 'category' => $this->category('footwear', 'Обувь'),
            'season' => $season, 'title' => 'Зимние ботинки для '.$child->getFirstName(),
            'quantity' => 1, 'size' => '31', 'notes' => 'Для школы',
        ]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function families(): FamilyService
    {
        return static::getContainer()->get(FamilyService::class);
    }

    private function matrix(): FamilyWardrobeMatrix
    {
        return static::getContainer()->get(FamilyWardrobeMatrix::class);
    }

    private function ids(array $records): array
    {
        return array_map(static fn (WardrobeItem|WardrobeNeed $record): int => $record->getId(), $records);
    }
}
