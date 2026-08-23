<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\Notification;
use App\Service\FamilyBudgetService;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
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

        $this->expectException(\DomainException::class);
        $this->purchaseRequests()->decide(
            $parent,
            $purchaseRequest,
            PurchaseRequest::STATUS_APPROVED,
        );
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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function email(string $prefix): string
    {
        return sprintf('%s-%s@test.local', $prefix, bin2hex(random_bytes(6)));
    }
}
