<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration tests for "Мой гардероб": /account/wardrobe/*
 *
 * Run with: php bin/phpunit tests/Controller/WardrobeControllerTest.php
 */
class WardrobeControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe');

        $this->assertResponseRedirects('/login', 302);
    }

    public function testIndexShowsEmptyState(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('GET', '/account/wardrobe');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Мой гардероб');
        $this->assertSelectorTextContains('body', 'пусто');
    }

    public function testNewItemFormCreatesWardrobeItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        $crawler = $client->request('GET', '/account/wardrobe/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить')->form([
            'wardrobe_item_form[category]' => 'Футболки',
            'wardrobe_item_form[name]'     => 'Тестовая футболка',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/wardrobe');

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = $em->getRepository(WardrobeItem::class)->findOneBy(['user' => $user, 'name' => 'Тестовая футболка']);

        $this->assertNotNull($item);
        $this->assertSame(1, $item->getItemNo());
        $this->assertSame(WardrobeItem::SOURCE_WEB, $item->getSource());
    }

    public function testIndexShowsStats(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item1 = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(101)
            ->setCategory('Худи')
            ->setName('Худи чёрное')
            ->setPrice('3500.00');
        $item2 = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(102)
            ->setCategory('Ботинки')
            ->setName('Ботинки зимние')
            ->setPrice('6500.00');
        $em->persist($item1);
        $em->persist($item2);
        $em->flush();

        $client->request('GET', '/account/wardrobe');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Худи');
        $this->assertSelectorTextContains('body', 'Ботинки');
        $this->assertSelectorTextContains('body', '10 000');
    }

    public function testShowAndEditReturn404ForOtherUsersItem(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        $foreignItem = (new WardrobeItem())
            ->setUser($otherUser)
            ->setItemNo(201)
            ->setCategory('Платья')
            ->setName('Чужое платье');
        $em->persist($foreignItem);
        $em->flush();
        $id = $foreignItem->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/account/wardrobe/' . $id . '/edit');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteOtherUsersItemReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $otherUser = UserFactory::brandOwner(static::getContainer());
        $foreignItem = (new WardrobeItem())
            ->setUser($otherUser)
            ->setItemNo(202)
            ->setCategory('Платья')
            ->setName('Чужое платье 2');
        $em->persist($foreignItem);
        $em->flush();
        $id = $foreignItem->getId();

        $csrfToken = $this->grabDeleteCsrfToken($client, $em, $user);

        $client->request('POST', '/account/wardrobe/' . $id . '/delete', ['_token' => $csrfToken]);
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Токен 'delete_wardrobe_item' одинаков для всех вещей — берём его со страницы
     * show своей (только что созданной служебной) вещи, чтобы не лезть в CSRF-сессию напрямую.
     */
    private function grabDeleteCsrfToken($client, EntityManagerInterface $em, User $user): string
    {
        $tokenItem = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo($em->getRepository(WardrobeItem::class)->count([]) + 9000)
            ->setCategory('Служебное')
            ->setName('Служебная вещь для CSRF');
        $em->persist($tokenItem);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/' . $tokenItem->getId());

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    public function testDeleteSoftDeletesItem(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(301)
            ->setCategory('Ремни')
            ->setName('Ремень кожаный');
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $crawler   = $client->request('GET', '/account/wardrobe/' . $id);
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/account/wardrobe/' . $id . '/delete', ['_token' => $csrfToken]);
        $this->assertResponseRedirects('/account/wardrobe');

        $em->clear();
        /** @var WardrobeItem $reloaded */
        $reloaded = $em->getRepository(WardrobeItem::class)->find($id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->getDeletedAt());

        $crawler = $client->request('GET', '/account/wardrobe');
        // в тест-БД могут жить вещи из соседних тестов — проверяем отсутствие именно этой
        $this->assertStringNotContainsString('Ремень кожаный', $crawler->filter('body')->text());
    }

    public function testShowDeletedItemReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->loginAsCustomer($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(401)
            ->setCategory('Сапоги')
            ->setName('Сапоги удалённые');
        $item->softDelete();
        $em->persist($item);
        $em->flush();
        $id = $item->getId();

        $client->request('GET', '/account/wardrobe/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }
}
