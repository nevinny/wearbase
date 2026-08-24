<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\PurchaseRequestItem;
use App\Entity\ExternalNotificationOutbox;
use App\Entity\FittingFeedback;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\FamilyBudgetService;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use App\Service\Wardrobe\PurchaseToWardrobeService;
use Doctrine\ORM\EntityManagerInterface;

class PurchaseRequestControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testChildCanCreateAndReadOwnRequestWithoutFetchingProvider(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('create-parent'));
        $child = $this->families()->createChild($parent, 'Анна');
        $client->loginUser($child);

        $crawler = $client->request('GET', '/account/purchases/new');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form([
            'purchase_request_form[subject]' => '0',
            'purchase_request_form[productUrl]' => 'https://shop.example.test/product/42',
            'purchase_request_form[comment]' => 'Нужна для школы',
        ]);
        $client->submit($form);

        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $this->em()->getRepository(PurchaseRequest::class)->findOneBy([
            'subject' => $child,
            'productUrl' => 'https://shop.example.test/product/42',
        ]);
        $this->assertNotNull($purchaseRequest);
        $this->assertResponseRedirects('/account/purchases/'.$purchaseRequest->getId());
        $this->assertSame(PurchaseRequest::STATUS_PENDING, $purchaseRequest->getStatus());
        $this->assertSame([PurchaseRequestEvent::TYPE_CREATED], array_map(
            static fn (PurchaseRequestEvent $event): string => $event->getType(),
            $purchaseRequest->getEvents()->toArray(),
        ));

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testFormRejectsNonHttpsUrl(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('http-parent'));
        $child = $this->families()->createChild($parent, 'Лиза');
        $client->loginUser($child);

        $crawler = $client->request('GET', '/account/purchases/new');
        $client->submit($crawler->filter('form')->form([
            'purchase_request_form[subject]' => '0',
            'purchase_request_form[productUrl]' => 'http://shop.example.test/product/42',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
    }

    public function testChildCreatesMultiItemRequestAndParentDecidesEachItem(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('multi-parent'));
        $child = $this->families()->createChild($parent, 'Аня');
        $client->loginUser($child);

        $crawler = $client->request('GET', '/account/purchases/new');
        $client->submit($crawler->filter('form')->form([
            'purchase_request_form[subject]' => '0',
            'purchase_request_form[productUrl]' => 'https://one.example.test/item/1',
            'purchase_request_form[additionalUrls]' => "https://two.example.test/item/2\nhttps://three.example.test/item/3",
            'purchase_request_form[estimatedPrice]' => '1000',
        ]));

        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $this->em()->getRepository(PurchaseRequest::class)->findOneBy(['subject' => $child]);
        $this->assertNotNull($purchaseRequest);
        $this->assertCount(3, $purchaseRequest->getItems());
        $items = $purchaseRequest->getItems()->toArray();

        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(3, '[data-purchase-item]');

        $approveToken = (string) $crawler->filter(sprintf('[data-purchase-item="%d"] input[name="_token"]', $items[0]->getId()))->first()->attr('value');
        $client->request('POST', sprintf('/account/purchases/%d/items/%d/decide', $purchaseRequest->getId(), $items[0]->getId()), [
            '_token' => $approveToken,
            'decision' => PurchaseRequest::STATUS_APPROVED,
        ]);
        $this->assertResponseRedirects('/account/purchases/'.$purchaseRequest->getId());

        $crawler = $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $rejectToken = (string) $crawler->filter(sprintf('[data-purchase-item="%d"] input[name="_token"]', $items[1]->getId()))->first()->attr('value');
        $client->request('POST', sprintf('/account/purchases/%d/items/%d/decide', $purchaseRequest->getId(), $items[1]->getId()), [
            '_token' => $rejectToken,
            'decision' => PurchaseRequest::STATUS_REJECTED,
            'decisionComment' => 'Уже есть похожая вещь',
        ]);
        $this->assertResponseRedirects('/account/purchases/'.$purchaseRequest->getId());

        $purchaseRequest = $this->em()->getRepository(PurchaseRequest::class)->find($purchaseRequest->getId());
        $items = $purchaseRequest->getItems()->toArray();
        $parent = $this->em()->getRepository(User::class)->find($parent->getId());
        $this->assertSame(PurchaseRequest::STATUS_PENDING, $purchaseRequest->getStatus());
        $this->purchaseRequests()->decideItem($parent, $purchaseRequest, $items[2], PurchaseRequest::STATUS_REJECTED, 'Не подходит');
        $this->assertSame(PurchaseRequest::STATUS_PARTIAL, $purchaseRequest->getStatus());
        $this->assertSame(PurchaseRequestItem::STATUS_APPROVED, $items[0]->getStatus());
        $this->assertSame(PurchaseRequestItem::STATUS_REJECTED, $items[1]->getStatus());
    }

    public function testItemDecisionRejectsItemFromAnotherRequest(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('nested-parent'));
        $child = $this->families()->createChild($parent, 'Лиза');
        $first = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/first', null);
        $second = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/second', null);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->purchaseRequests()->decideItem(
            $parent,
            $first,
            $second->getItems()->first(),
            PurchaseRequest::STATUS_APPROVED,
        );
    }

    public function testParentOrdersAndDeliversThenChildRecordsSuccessfulFitting(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('fulfillment-parent'));
        $child = $this->families()->createChild($parent, 'Маша');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/dress', null, '4200');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();

        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, '3999.90');
        $this->assertSame(PurchaseRequestItem::STATUS_ORDERED, $item->getStatus());
        $this->assertSame('3999.90', $item->getActualPrice());
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->purchaseRequests()->recordFitting(
            $child,
            $request,
            $item,
            FittingFeedback::OUTCOME_BOUGHT,
            'S',
            FittingFeedback::SIZING_TRUE,
            [],
            'Хорошо село по фигуре',
        );

        $this->assertSame(PurchaseRequestItem::STATUS_BOUGHT, $item->getStatus());
        $this->assertSame('S', $item->getFittingFeedback()?->getTriedSize());
        $this->assertSame($child->getId(), $item->getFittingFeedback()?->getActor()->getId());
        $this->assertSame(
            [PurchaseRequestEvent::TYPE_CREATED, PurchaseRequestEvent::TYPE_APPROVED, PurchaseRequestEvent::TYPE_ORDERED, PurchaseRequestEvent::TYPE_DELIVERED, PurchaseRequestEvent::TYPE_FITTING],
            array_map(static fn (PurchaseRequestEvent $event): string => $event->getType(), $request->getEvents()->toArray()),
        );
    }

    public function testFulfillmentRejectsInvalidTransitionAndSiblingFeedback(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('fulfillment-guard-parent'));
        $child = $this->families()->createChild($parent, 'Лиза');
        $sibling = $this->families()->createChild($parent, 'Настя');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/shoes', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();

        try {
            $this->purchaseRequests()->markDelivered($parent, $request, $item);
            $this->fail('Pending item cannot be delivered');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->purchaseRequests()->recordFitting($sibling, $request, $item, FittingFeedback::OUTCOME_REFUSED, null, null, [], null);
    }

    public function testFulfillmentEndpointRequiresCsrf(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('fulfillment-csrf-parent'));
        $child = $this->families()->createChild($parent, 'Вера');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/jacket', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $client->loginUser($parent);

        $client->request('POST', sprintf('/account/purchases/%d/items/%d/fulfillment', $request->getId(), $item->getId()), [
            '_token' => 'invalid',
            'action' => 'ordered',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(PurchaseRequestItem::class)->find($item->getId());
        $this->assertSame(PurchaseRequestItem::STATUS_APPROVED, $reloaded->getStatus());
    }

    public function testBoughtPositionCreatesExactlyOneWardrobeItemForChild(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('purchase-wardrobe-parent'));
        $child = $this->families()->createChild($parent, 'Мила');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/cardigan', null, '2100');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, '1999');
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_BOUGHT, '152', FittingFeedback::SIZING_TRUE, [], null);

        /** @var PurchaseToWardrobeService $converter */
        $converter = static::getContainer()->get(PurchaseToWardrobeService::class);
        $first = $converter->add($parent, $request, $item);
        $second = $converter->add($parent, $request, $item);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($child->getId(), $first->getUser()?->getId());
        $this->assertSame($child->getId(), $first->getOriginalOwner()?->getId());
        $this->assertSame('1999', $first->getPrice());
        $this->assertSame('152', $first->getSize());
        $this->assertSame($item->getSourceUrl(), $first->getProductUrl());
        $this->assertSame(1, $this->em()->getRepository(\App\Entity\WardrobeItem::class)->count(['user' => $child]));
        $this->assertSame(PurchaseRequestEvent::TYPE_ADDED_TO_WARDROBE, $request->getEvents()->last()->getType());
    }

    public function testReturningBoughtPositionArchivesLinkedWardrobeItemExactlyOnce(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('purchase-return-parent'));
        $child = $this->families()->createChild($parent, 'Соня');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/coat', null, '8000');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, '7600');
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_BOUGHT, '152', FittingFeedback::SIZING_TRUE, [], null);

        /** @var PurchaseToWardrobeService $converter */
        $converter = static::getContainer()->get(PurchaseToWardrobeService::class);
        $wardrobeItem = $converter->add($parent, $request, $item);
        $eventsBeforeReturn = $request->getEvents()->count();

        $this->purchaseRequests()->markReturned($parent, $request, $item);
        $this->purchaseRequests()->markReturned($parent, $request, $item);

        $this->assertSame(PurchaseRequestItem::STATUS_RETURNED, $item->getStatus());
        $this->assertSame(WardrobeItem::ITEM_RETURNED, $wardrobeItem->getItemStatus());
        $this->assertContains(WardrobeItem::ITEM_RETURNED, WardrobeItem::ARCHIVE_STATUSES);
        $this->assertSame($eventsBeforeReturn + 1, $request->getEvents()->count());
        $this->assertSame(PurchaseRequestEvent::TYPE_RETURNED, $request->getEvents()->last()->getType());
    }

    public function testRefusedPositionCannotCreateWardrobeItem(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('refused-wardrobe-parent'));
        $child = $this->families()->createChild($parent, 'Оля');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/trousers', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, null);
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_REFUSED, null, null, ['waist'], 'Не сели');

        $this->expectException(\DomainException::class);
        static::getContainer()->get(PurchaseToWardrobeService::class)->add($parent, $request, $item);
    }

    public function testFormRejectsMoreThanTenItems(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('limit-parent'));
        $child = $this->families()->createChild($parent, 'Лиза');
        $client->loginUser($child);
        $urls = implode("\n", array_map(static fn (int $id): string => 'https://shop.example.test/item/'.$id, range(2, 11)));

        $crawler = $client->request('GET', '/account/purchases/new');
        $client->submit($crawler->filter('form')->form([
            'purchase_request_form[subject]' => '0',
            'purchase_request_form[productUrl]' => 'https://shop.example.test/item/1',
            'purchase_request_form[additionalUrls]' => $urls,
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em()->getRepository(PurchaseRequest::class)->count(['subject' => $child]));
    }

    public function testParentCanCreateForChildAndApproveWithAuditEvent(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('approve-parent'));
        $child = $this->families()->createChild($parent, 'Маша');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://merchant.example.test/item/7',
            null,
        );
        $client->loginUser($parent);

        $crawler = $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/account/purchases/'.$purchaseRequest->getId().'/decide', [
            '_token' => $token,
            'decision' => PurchaseRequest::STATUS_APPROVED,
        ]);

        $this->assertResponseRedirects('/account/purchases/'.$purchaseRequest->getId());
        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $this->em()->getRepository(PurchaseRequest::class)->find($purchaseRequest->getId());
        $this->assertSame(PurchaseRequest::STATUS_APPROVED, $purchaseRequest->getStatus());
        $this->assertSame($parent->getId(), $purchaseRequest->getDecidedBy()?->getId());
        $this->assertSame(
            [PurchaseRequestEvent::TYPE_CREATED, PurchaseRequestEvent::TYPE_APPROVED],
            array_map(static fn (PurchaseRequestEvent $event): string => $event->getType(), $purchaseRequest->getEvents()->toArray()),
        );
    }

    public function testSiblingAndForeignUserCannotReadOrDecide(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('idor-parent'));
        $child = $this->families()->createChild($parent, 'Полина');
        $sibling = $this->families()->createChild($parent, 'Соня');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://merchant.example.test/item/8',
            null,
        );

        $client->loginUser($sibling);
        $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $this->assertResponseStatusCodeSame(403);
        try {
            $this->purchaseRequests()->decide($sibling, $purchaseRequest, PurchaseRequest::STATUS_REJECTED, 'Обсудим позже');
            $this->fail('Sibling decision must be denied');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $foreign = UserFactory::withEmail(static::getContainer(), $this->email('idor-foreign'));
        $client->loginUser($foreign);
        $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDecisionRequiresValidCsrfAndIsFinal(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('csrf-parent'));
        $child = $this->families()->createChild($parent, 'Катя');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://merchant.example.test/item/9',
            null,
        );
        $client->loginUser($parent);

        $client->request('POST', '/account/purchases/'.$purchaseRequest->getId().'/decide', [
            '_token' => 'invalid',
            'decision' => PurchaseRequest::STATUS_APPROVED,
        ]);
        $this->assertResponseStatusCodeSame(403);
        $this->em()->refresh($purchaseRequest);
        $this->assertSame(PurchaseRequest::STATUS_PENDING, $purchaseRequest->getStatus());

        $this->purchaseRequests()->decide($parent, $purchaseRequest, PurchaseRequest::STATUS_REJECTED, 'Обсудим позже');
        $this->expectException(\DomainException::class);
        $this->purchaseRequests()->decide($parent, $purchaseRequest, PurchaseRequest::STATUS_APPROVED);
    }

    public function testRequestNotifiesParentAndDecisionNotifiesChild(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('notify-parent'));
        $child = $this->families()->createChild($parent, 'Алиса');

        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/notice',
            'Можно купить?',
            '2500',
        );

        $parentNotification = $this->em()->getRepository(Notification::class)->findOneBy([
            'recipient' => $parent,
            'type' => Notification::TYPE_PURCHASE_REQUEST_NEW,
        ]);
        $this->assertNotNull($parentNotification);
        $this->assertSame('/account/purchases/'.$purchaseRequest->getId(), $parentNotification->getData()['url']);

        $this->purchaseRequests()->decide(
            $parent,
            $purchaseRequest,
            PurchaseRequest::STATUS_APPROVED,
            'Закажем',
        );

        $childNotification = $this->em()->getRepository(Notification::class)->findOneBy([
            'recipient' => $child,
            'type' => Notification::TYPE_PURCHASE_REQUEST_DECIDED,
        ]);
        $this->assertNotNull($childNotification);
        $this->assertSame('Покупка одобрена', $childNotification->getTitle());
    }

    public function testRequestNotifiesBothParentsButNotCreatingActor(): void
    {
        $firstParent = UserFactory::withEmail(static::getContainer(), $this->email('notify-first-parent'));
        $secondParent = UserFactory::withEmail(static::getContainer(), $this->email('notify-second-parent'));
        $invite = $this->families()->createInvite($firstParent, User::FAMILY_ROLE_PARENT);
        $this->families()->acceptInvite($secondParent, $invite);
        $child = $this->families()->createChild($firstParent, 'Мила');

        $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/two-parents',
            'Посмотрите вместе',
            '1200',
        );
        $notifications = $this->em()->getRepository(Notification::class)->findBy([
            'type' => Notification::TYPE_PURCHASE_REQUEST_NEW,
        ]);
        $recipientIds = array_map(
            static fn (Notification $notification): ?int => $notification->getRecipient()?->getId(),
            $notifications,
        );
        $this->assertContains($firstParent->getId(), $recipientIds);
        $this->assertContains($secondParent->getId(), $recipientIds);
        $this->assertNotContains($child->getId(), $recipientIds);

        $this->purchaseRequests()->create(
            $firstParent,
            $child,
            'https://shop.example.test/item/created-by-parent',
            null,
            '800',
        );
        $actorNotifications = $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $firstParent,
            'type' => Notification::TYPE_PURCHASE_REQUEST_NEW,
        ]);
        $secondParentNotifications = $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $secondParent,
            'type' => Notification::TYPE_PURCHASE_REQUEST_NEW,
        ]);
        $this->assertCount(1, $actorNotifications);
        $this->assertCount(2, $secondParentNotifications);
    }

    public function testChildFittingOutcomeNotifiesParentsOnceWithDistinctType(): void
    {
        $firstParent = UserFactory::withEmail(static::getContainer(), $this->email('fitting-first-parent'));
        $secondParent = UserFactory::withEmail(static::getContainer(), $this->email('fitting-second-parent'));
        $invite = $this->families()->createInvite($firstParent, User::FAMILY_ROLE_PARENT);
        $this->families()->acceptInvite($secondParent, $invite);
        $child = $this->families()->createChild($firstParent, 'Мила');
        $sibling = $this->families()->createChild($firstParent, 'Лиза');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/item/fitting-notice', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($firstParent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($firstParent, $request, $item, null);
        $this->purchaseRequests()->markDelivered($firstParent, $request, $item);

        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_PENDING, '152', null, [], null);

        $notifications = $this->em()->getRepository(Notification::class)->findBy([
            'type' => Notification::TYPE_PURCHASE_FITTING,
        ]);
        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [$firstParent->getId(), $secondParent->getId()],
            array_map(static fn (Notification $notification): ?int => $notification->getRecipient()?->getId(), $notifications),
        );
        $this->assertNotContains($child->getId(), array_map(static fn (Notification $notification): ?int => $notification->getRecipient()?->getId(), $notifications));
        $this->assertNotContains($sibling->getId(), array_map(static fn (Notification $notification): ?int => $notification->getRecipient()?->getId(), $notifications));

        $firstNotification = array_values(array_filter(
            $notifications,
            static fn (Notification $notification): bool => $notification->getRecipient()?->getId() === $firstParent->getId(),
        ))[0];
        static::getContainer()->get(\App\Notification\NotificationDispatcher::class)->dispatchInAppOnce(
            $firstParent,
            Notification::TYPE_PURCHASE_FITTING,
            (string) $firstNotification->getDedupeKey(),
            'Повторная доставка',
        );
        $this->em()->flush();
        $this->assertCount(1, $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $firstParent,
            'type' => Notification::TYPE_PURCHASE_FITTING,
        ]));
    }

    public function testBoughtNotificationHonoursEachParentsInAppSetting(): void
    {
        $firstParent = UserFactory::withEmail(static::getContainer(), $this->email('bought-first-parent'));
        $secondParent = UserFactory::withEmail(static::getContainer(), $this->email('bought-second-parent'));
        $invite = $this->families()->createInvite($firstParent, User::FAMILY_ROLE_PARENT);
        $this->families()->acceptInvite($secondParent, $invite);
        $child = $this->families()->createChild($firstParent, 'Аня');
        $settings = (new \App\Entity\NotificationSettings())
            ->setUser($secondParent)
            ->setEventType(Notification::TYPE_PURCHASE_BOUGHT)
            ->setChannelInapp(false);
        $this->em()->persist($settings);
        $this->em()->flush();
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/item/bought-notice', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($firstParent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($firstParent, $request, $item, null);
        $this->purchaseRequests()->markDelivered($firstParent, $request, $item);

        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_BOUGHT, null, null, [], null);

        $this->assertCount(1, $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $firstParent,
            'type' => Notification::TYPE_PURCHASE_BOUGHT,
        ]));
        $this->assertCount(0, $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $secondParent,
            'type' => Notification::TYPE_PURCHASE_BOUGHT,
        ]));
    }

    public function testChildFittingQueuesEnabledExternalChannelsOnce(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('external-parent'));
        $parent->setTelegramChatId('123456');
        $child = $this->families()->createChild($parent, 'Аня');
        $settings = (new \App\Entity\NotificationSettings())
            ->setUser($parent)
            ->setEventType(Notification::TYPE_PURCHASE_FITTING)
            ->setChannelEmail(true)
            ->setChannelTelegram(true)
            ->setChannelPush(true);
        $this->em()->persist($settings);
        $this->em()->flush();
        $request = $this->deliveredRequest($parent, $child, 'external-fitting');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();

        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_PENDING, null, null, [], null);

        $rows = $this->em()->getRepository(ExternalNotificationOutbox::class)->findBy([
            'recipient' => $parent,
            'notificationType' => Notification::TYPE_PURCHASE_FITTING,
        ]);
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            [Notification::CHANNEL_EMAIL, Notification::CHANNEL_TELEGRAM, Notification::CHANNEL_PUSH],
            array_map(static fn (ExternalNotificationOutbox $row): string => $row->getChannel(), $rows),
        );

        static::getContainer()->get(\App\Notification\NotificationDispatcher::class)->dispatchOnce(
            $parent,
            Notification::TYPE_PURCHASE_FITTING,
            sprintf('purchase-item:%d:%s:recipient:%d', $item->getId(), Notification::TYPE_PURCHASE_FITTING, $parent->getId()),
            'Повторная доставка',
            emailTemplate: 'family_notification',
            emailContext: ['title' => 'Повторная доставка', 'body' => null, 'url' => null],
        );
        $this->em()->flush();
        $this->assertCount(3, $this->em()->getRepository(ExternalNotificationOutbox::class)->findBy([
            'recipient' => $parent,
            'notificationType' => Notification::TYPE_PURCHASE_FITTING,
        ]));
    }

    public function testRefusedAndReturnedUseDistinctParentNotificationTypes(): void
    {
        $firstParent = UserFactory::withEmail(static::getContainer(), $this->email('status-first-parent'));
        $secondParent = UserFactory::withEmail(static::getContainer(), $this->email('status-second-parent'));
        $invite = $this->families()->createInvite($firstParent, User::FAMILY_ROLE_PARENT);
        $this->families()->acceptInvite($secondParent, $invite);
        $child = $this->families()->createChild($firstParent, 'Саша');

        $refusedRequest = $this->deliveredRequest($firstParent, $child, 'refused-notice');
        /** @var PurchaseRequestItem $refusedItem */
        $refusedItem = $refusedRequest->getItems()->first();
        $this->purchaseRequests()->recordFitting($child, $refusedRequest, $refusedItem, FittingFeedback::OUTCOME_REFUSED, null, null, [], 'Не подошло');

        $returnedRequest = $this->deliveredRequest($firstParent, $child, 'returned-notice');
        /** @var PurchaseRequestItem $returnedItem */
        $returnedItem = $returnedRequest->getItems()->first();
        $this->purchaseRequests()->recordFitting($child, $returnedRequest, $returnedItem, FittingFeedback::OUTCOME_BOUGHT, null, null, [], null);
        $this->purchaseRequests()->markReturned($firstParent, $returnedRequest, $returnedItem);

        foreach ([$firstParent, $secondParent] as $parent) {
            $this->assertCount(1, $this->em()->getRepository(Notification::class)->findBy([
                'recipient' => $parent,
                'type' => Notification::TYPE_PURCHASE_REFUSED,
            ]));
        }
        $this->assertCount(0, $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $firstParent,
            'type' => Notification::TYPE_PURCHASE_RETURNED,
        ]));
        $this->assertCount(1, $this->em()->getRepository(Notification::class)->findBy([
            'recipient' => $secondParent,
            'type' => Notification::TYPE_PURCHASE_RETURNED,
        ]));
    }

    public function testMonthlyBudgetRequiresExplicitOverspendApproval(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-parent'));
        $child = $this->families()->createChild($parent, 'Маша');
        static::getContainer()->get(FamilyBudgetService::class)->setMonthlyLimit($parent, $child, '3000');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/expensive',
            null,
            '4500',
        );

        try {
            $this->purchaseRequests()->decide(
                $parent,
                $purchaseRequest,
                PurchaseRequest::STATUS_APPROVED,
            );
            $this->fail('Overspend must require explicit confirmation');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('превышает', $e->getMessage());
        }
        $this->assertSame(PurchaseRequest::STATUS_PENDING, $purchaseRequest->getStatus());

        $this->purchaseRequests()->decide(
            $parent,
            $purchaseRequest,
            PurchaseRequest::STATUS_APPROVED,
            'Разрешаю исключение',
            true,
        );

        $summary = static::getContainer()->get(FamilyBudgetService::class)->summary($child);
        $this->assertSame('3000.00', $summary['limit']);
        $this->assertSame('4500.00', $summary['approved']);
        $this->assertSame('-1500.00', $summary['remaining']);
        $event = $purchaseRequest->getEvents()->last();
        $this->assertSame(PurchaseRequestEvent::TYPE_APPROVED_OVER_BUDGET, $event->getType());
        $this->assertTrue($event->getMetadata()['override']);
    }

    public function testOnlyParentCanOpenBudgetScreen(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-screen-parent'));
        $child = $this->families()->createChild($parent, 'Лиза');

        $client->loginUser($parent);
        $client->request('GET', '/account/purchases/budget/manage');
        $this->assertResponseIsSuccessful();

        $client->loginUser($child);
        $client->request('GET', '/account/purchases/budget/manage');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testParentCanSaveMonthlyBudgetThroughForm(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-form-parent'));
        $child = $this->families()->createChild($parent, 'Саша');
        $client->loginUser($parent);

        $crawler = $client->request('GET', '/account/purchases/budget/manage');
        $form = $crawler->selectButton('Сохранить бюджет')->form([
            'family_budget_form[subject]' => '0',
            'family_budget_form[monthlyLimit]' => '12000',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/purchases');
        $summary = static::getContainer()->get(FamilyBudgetService::class)->summary($child);
        $this->assertSame('12000.00', $summary['limit']);
        $this->assertSame('12000.00', $summary['remaining']);
    }

    public function testBudgetRequiresExplicitApprovalWhenPriceIsUnknown(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('unknown-price-parent'));
        $child = $this->families()->createChild($parent, 'Даша');
        static::getContainer()->get(FamilyBudgetService::class)->setMonthlyLimit($parent, $child, '5000');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/unknown-price',
            null,
        );

        try {
            $this->purchaseRequests()->decide($parent, $purchaseRequest, PurchaseRequest::STATUS_APPROVED);
            $this->fail('Unknown price must require explicit confirmation');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Укажите цену', $exception->getMessage());
        }

        $this->purchaseRequests()->decide(
            $parent,
            $purchaseRequest,
            PurchaseRequest::STATUS_APPROVED,
            'Цена появится при оформлении',
            true,
        );
        $event = $purchaseRequest->getEvents()->last();
        $this->assertSame(PurchaseRequestEvent::TYPE_APPROVED_NO_PRICE, $event->getType());
        $this->assertSame('unknown_price', $event->getMetadata()['reason']);
        $this->assertTrue($event->getMetadata()['override']);
    }

    public function testBudgetUsesExactKopecks(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('exact-budget-parent'));
        $child = $this->families()->createChild($parent, 'Лида');
        static::getContainer()->get(FamilyBudgetService::class)->setMonthlyLimit($parent, $child, '1.00');
        foreach (['0.01', '0.02'] as $index => $price) {
            $request = $this->purchaseRequests()->create(
                $child,
                $child,
                'https://shop.example.test/item/kopeck-'.$index,
                null,
                $price,
            );
            $this->purchaseRequests()->decide($parent, $request, PurchaseRequest::STATUS_APPROVED);
        }

        $summary = static::getContainer()->get(FamilyBudgetService::class)->summary($child);
        $this->assertSame('0.03', $summary['approved']);
        $this->assertSame('0.97', $summary['remaining']);
    }

    public function testOrderedDeliveredAndBoughtKeepActualCommitmentUntilReturn(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-lifecycle-parent'));
        $child = $this->families()->createChild($parent, 'Лена');
        $budgets = static::getContainer()->get(FamilyBudgetService::class);
        $budgets->setMonthlyLimit($parent, $child, '5000');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/budget-lifecycle', null, '2000');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);

        $this->purchaseRequests()->markOrdered($parent, $request, $item, '2500.01');
        $this->assertSame('2500.01', $budgets->summary($child)['approved']);
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->assertSame('2500.01', $budgets->summary($child)['approved']);
        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_BOUGHT, null, null, [], null);
        $this->assertSame('2500.01', $budgets->summary($child)['approved']);
        $this->purchaseRequests()->markReturned($parent, $request, $item);
        $this->assertSame('0.00', $budgets->summary($child)['approved']);
    }

    public function testRefusedItemReleasesCommitment(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-refused-parent'));
        $child = $this->families()->createChild($parent, 'Люба');
        $budgets = static::getContainer()->get(FamilyBudgetService::class);
        $budgets->setMonthlyLimit($parent, $child, '3000');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/budget-refused', null, '1200');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, '1100');
        $this->assertSame('1100.00', $budgets->summary($child)['approved']);
        $this->purchaseRequests()->markDelivered($parent, $request, $item);
        $this->purchaseRequests()->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_REFUSED, null, null, [], 'Не подошло');

        $this->assertSame('0.00', $budgets->summary($child)['approved']);
    }

    public function testActualPriceIncreaseRequiresOverrideAndAuditsExactSnapshot(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('actual-override-parent'));
        $child = $this->families()->createChild($parent, 'Инна');
        static::getContainer()->get(FamilyBudgetService::class)->setMonthlyLimit($parent, $child, '100.00');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/actual-override', null, '99.99');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);

        try {
            $this->purchaseRequests()->markOrdered($parent, $request, $item, '100.01');
            $this->fail('Actual overspend must require explicit confirmation');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Фактическая цена', $exception->getMessage());
        }
        $this->assertSame(PurchaseRequestItem::STATUS_APPROVED, $item->getStatus());

        $this->purchaseRequests()->markOrdered($parent, $request, $item, '100.01', true);
        $event = $request->getEvents()->last();
        $this->assertSame(PurchaseRequestEvent::TYPE_ORDERED_OVER_BUDGET, $event->getType());
        $this->assertSame('99.99', $event->getMetadata()['committedBefore']);
        $this->assertSame('100.01', $event->getMetadata()['committedAfter']);
        $this->assertSame('0.02', $event->getMetadata()['delta']);
        $this->assertSame('-0.01', $event->getMetadata()['remainingAfter']);
        $this->assertTrue($event->getMetadata()['override']);
    }

    public function testCompetingOrdersUseLatestLockedActualCommitment(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('actual-concurrency-parent'));
        $child = $this->families()->createChild($parent, 'Ася');
        static::getContainer()->get(FamilyBudgetService::class)->setMonthlyLimit($parent, $child, '100');
        $requests = [];
        foreach (['first', 'second'] as $slug) {
            $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/'.$slug, null, '20');
            /** @var PurchaseRequestItem $item */
            $item = $request->getItems()->first();
            $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
            $requests[] = [$request, $item];
        }

        $this->purchaseRequests()->markOrdered($parent, $requests[0][0], $requests[0][1], '80');
        $this->assertSame('100.00', static::getContainer()->get(FamilyBudgetService::class)->summary($child)['approved']);
        $this->expectException(\DomainException::class);
        $this->purchaseRequests()->markOrdered($parent, $requests[1][0], $requests[1][1], '30');
    }

    public function testBudgetUsesDecisionMonthBoundaryAfterOrdering(): void
    {
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('budget-month-parent'));
        $child = $this->families()->createChild($parent, 'Майя');
        $budgets = static::getContainer()->get(FamilyBudgetService::class);
        $budgets->setMonthlyLimit($parent, $child, '5000');
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/month-boundary', null, '700');
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->em()->getConnection()->update('purchase_request_item', ['decided_at' => '2026-07-31 23:59:59'], ['id' => $item->getId()]);
        $this->em()->refresh($item);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, '750');

        $this->assertSame('750.00', $budgets->summary($child, null, new \DateTimeImmutable('2026-07-15'))['approved']);
        $this->assertSame('0.00', $budgets->summary($child, null, new \DateTimeImmutable('2026-08-15'))['approved']);
    }

    public function testNotificationRendersSafeRequestLink(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('notification-link-parent'));
        $child = $this->families()->createChild($parent, 'Вера');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/notification-link',
            null,
            '1900',
        );
        $client->loginUser($parent);

        $client->request('GET', '/account/notifications');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'a[href="/account/purchases/%d"]',
            $purchaseRequest->getId(),
        ));
    }

    public function testFormerFamilyCannotReadOrDecideAfterChildMovesFamily(): void
    {
        $client = static::createClient();
        $oldParent = UserFactory::withEmail(static::getContainer(), $this->email('old-parent'));
        $child = $this->families()->createChild($oldParent, 'Нина');
        $purchaseRequest = $this->purchaseRequests()->create(
            $child,
            $child,
            'https://shop.example.test/item/old-family',
            null,
            '1000',
        );
        $newParent = UserFactory::withEmail(static::getContainer(), $this->email('new-parent'));
        $newFamilyChild = $this->families()->createChild($newParent, 'Временный профиль');
        $child->setFamily($newFamilyChild->getFamily());
        $this->em()->flush();

        $client->loginUser($oldParent);
        $client->request('GET', '/account/purchases/'.$purchaseRequest->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->purchaseRequests()->decide(
            $oldParent,
            $purchaseRequest,
            PurchaseRequest::STATUS_APPROVED,
        );
    }

    private function families(): FamilyService
    {
        return static::getContainer()->get(FamilyService::class);
    }

    private function purchaseRequests(): PurchaseRequestService
    {
        return static::getContainer()->get(PurchaseRequestService::class);
    }

    private function deliveredRequest(User $parent, User $child, string $slug): PurchaseRequest
    {
        $request = $this->purchaseRequests()->create($child, $child, 'https://shop.example.test/item/'.$slug, null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchaseRequests()->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchaseRequests()->markOrdered($parent, $request, $item, null);
        $this->purchaseRequests()->markDelivered($parent, $request, $item);

        return $request;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function email(string $prefix): string
    {
        return sprintf('%s-%s@test.local', $prefix, bin2hex(random_bytes(6)));
    }
}
