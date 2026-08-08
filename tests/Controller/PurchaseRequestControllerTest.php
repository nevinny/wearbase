<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
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
