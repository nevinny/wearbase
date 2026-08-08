<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\WardrobeItem;
use App\Service\FamilyService;
use App\Tests\Controller\AuthenticatedWebTestCase;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

class WardrobeAppControllerTest extends AuthenticatedWebTestCase
{
    public function testAuthenticationIsRequired(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/wardrobe-app/bootstrap');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            ['error' => 'authentication_required'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testBootstrapReturnsOnlyAccessibleMembersWithoutPrivateFields(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('bootstrap-parent'));
        $parent->setFirstName('Мария');
        $client->loginUser($parent);

        /** @var FamilyService $families */
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Анна');

        $client->request('GET', '/api/v1/wardrobe-app/bootstrap');

        $this->assertResponseIsSuccessful();
        $data = $this->json($client);
        $this->assertSame(['user', 'hasFamily', 'members'], array_keys($data));
        $this->assertSame($parent->getId(), $data['user']['id']);
        $this->assertTrue($data['hasFamily']);
        $this->assertSame([$parent->getId(), $child->getId()], array_column($data['members'], 'id'));
        $this->assertSame(
            ['id', 'displayName', 'familyRole', 'isSelf', 'canManage', 'itemCount'],
            array_keys($data['members'][0]),
        );
        $this->assertStringNotContainsString('email', strtolower((string) $client->getResponse()->getContent()));
        $this->assertStringNotContainsString('@family.wearbase.local', (string) $client->getResponse()->getContent());
    }

    public function testItemsReturnsSelectedManagedMembersActiveWardrobeWithStableSchema(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('items-parent'));
        $client->loginUser($parent);

        /** @var FamilyService $families */
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Лиза');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())
            ->setUser($child)
            ->setItemNo(1)
            ->setName('Синий свитер')
            ->setCategory('Свитеры')
            ->setCustomBrandName('Test Brand')
            ->setColorName('Синий')
            ->setSize('152')
            ->setSeason('Зима')
            ->setProductUrl('https://private.example.test/order/secret')
            ->setNotes('private-note');
        $em->persist($item);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe-app/items?member='.$child->getId());

        $this->assertResponseIsSuccessful();
        $data = $this->json($client);
        $this->assertSame(['member', 'items', 'page'], array_keys($data));
        $this->assertSame($child->getId(), $data['member']['id']);
        $this->assertCount(1, $data['items']);
        $this->assertSame(
            ['id', 'itemNo', 'name', 'category', 'brand', 'color', 'size', 'season', 'completionStatus', 'itemStatus', 'wearStatus'],
            array_keys($data['items'][0]),
        );
        $this->assertSame('Синий свитер', $data['items'][0]['name']);
        $this->assertSame(['limit' => 24, 'hasMore' => false, 'nextCursor' => null], $data['page']);
        $this->assertStringNotContainsString('private.example.test', (string) $client->getResponse()->getContent());
        $this->assertStringNotContainsString('private-note', (string) $client->getResponse()->getContent());
        $this->assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testChildBootstrapShowsFamilyWithoutOtherMembersItemCounts(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('child-bootstrap-parent'));
        $parent->setFirstName('Мария');

        /** @var FamilyService $families */
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Анна');
        $sibling = $families->createChild($parent, 'Лиза');
        $client->loginUser($child);

        $client->request('GET', '/api/v1/wardrobe-app/bootstrap');

        $this->assertResponseIsSuccessful();
        $members = $this->json($client)['members'];
        $this->assertSame([$parent->getId(), $child->getId(), $sibling->getId()], array_column($members, 'id'));

        $membersById = array_column($members, null, 'id');
        $this->assertFalse($membersById[$parent->getId()]['canManage']);
        $this->assertArrayNotHasKey('itemCount', $membersById[$parent->getId()]);
        $this->assertTrue($membersById[$child->getId()]['canManage']);
        $this->assertArrayHasKey('itemCount', $membersById[$child->getId()]);
        $this->assertFalse($membersById[$sibling->getId()]['canManage']);
        $this->assertArrayNotHasKey('itemCount', $membersById[$sibling->getId()]);
    }

    public function testChildCannotReadParentOrSiblingWardrobe(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('child-access-parent'));

        /** @var FamilyService $families */
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Анна');
        $sibling = $families->createChild($parent, 'Лиза');
        $client->loginUser($child);

        $client->request('GET', '/api/v1/wardrobe-app/items?member='.$parent->getId());
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/v1/wardrobe-app/items?member='.$sibling->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testItemsRejectsForeignMemberId(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $actor = UserFactory::withEmail(static::getContainer(), $this->email('idor-actor'));
        $stranger = UserFactory::withEmail(static::getContainer(), $this->email('idor-stranger'));
        $client->loginUser($actor);

        $client->request('GET', '/api/v1/wardrobe-app/items?member='.$stranger->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function email(string $prefix): string
    {
        return sprintf('%s-%s@test.local', $prefix, bin2hex(random_bytes(6)));
    }
}
