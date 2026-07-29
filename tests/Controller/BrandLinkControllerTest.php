<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandLink;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for brand LK link management: /brand/links/*
 *
 * Run with: php bin/phpunit tests/Controller/BrandLinkControllerTest.php
 */
class BrandLinkControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    /**
     * 'Test Brand' — общая идемпотентная фикстура (UserFactory::brandOwnerWithBrand),
     * переиспользуется всеми тестами прогона. Чистим ссылки, созданные ЭТИМ тестом,
     * иначе assertCount() в соседних тестах зависят от порядка выполнения.
     * Физический DELETE — тестовая уборка, не действие пользователя (правило soft-delete
     * не про это; см. CLAUDE.md «системные операции»).
     */
    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $conn->executeStatement(
            "DELETE FROM brand_link WHERE brand_id IN (SELECT id FROM brand WHERE slug = 'test-brand-lk' OR slug LIKE 'foreign-brand-%')"
        );
        $conn->executeStatement("DELETE FROM brand WHERE slug LIKE 'foreign-brand-%'");
        parent::tearDown();
    }

    public function testOwnerCanAddLinkAndItAppearsInLkAndOnBrandPage(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $crawler = $client->request('GET', '/brand/links');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Добавить')->form([
            'brand_link_form[linkType]' => 'website',
            'brand_link_form[linkUrl]'  => 'https://example.com',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/brand/links');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->find(Brand::class, $brand->getId());
        $this->assertCount(1, $reloaded->getActiveLinks());

        $crawler = $client->request('GET', '/brand/links');
        $this->assertSelectorTextContains('body', 'example.com');

        // «и у бренда» — публичная страница показывает ту же ссылку через прокладку /go/{id}
        $client->request('GET', '/ru/brands/' . $brand->getSlug());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'example.com');
    }

    public function testForeignLinkCannotBeEditedOrDeleted(): void
    {
        $client = static::createClient();
        $this->loginAsBrandOwnerWithBrand($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $foreignLink = $this->makeForeignLink($em, 'https://foreign-brand.example.com');
        $foreignId   = $foreignLink->getId();

        $client->request('POST', '/brand/links/save', [
            'id'              => $foreignId,
            'brand_link_form' => ['linkType' => 'website', 'linkUrl' => 'https://hijacked.example.com'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('POST', '/brand/links/' . $foreignId . '/delete', ['_token' => 'irrelevant']);
        $this->assertResponseStatusCodeSame(404);

        $em->clear();
        /** @var BrandLink $stillIntact */
        $stillIntact = $em->find(BrandLink::class, $foreignId);
        $this->assertSame('https://foreign-brand.example.com', $stillIntact->getLinkUrl());
        $this->assertNotSame(Statuses::Deleted, $stillIntact->getStatus());
    }

    #[DataProvider('invalidUrlProvider')]
    public function testInvalidUrlIsRejected(string $invalidUrl): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $crawler = $client->request('GET', '/brand/links');
        $form = $crawler->selectButton('Добавить')->form([
            'brand_link_form[linkType]' => 'website',
            'brand_link_form[linkUrl]'  => $invalidUrl,
        ]);
        $client->submit($form);

        // Symfony 7.3: невалидная форма в render() автоматически получает 422 (см. WardrobeControllerTest).
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('.form-error');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->find(Brand::class, $brand->getId());
        $this->assertCount(0, $reloaded->getActiveLinks());
    }

    public static function invalidUrlProvider(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'disallowed protocol' => ['ftp://example.com/file'];
        yield 'no scheme' => ['example.com'];
    }

    public function testDuplicateUrlIsRejected(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $crawler = $client->request('GET', '/brand/links');
        $client->submit($crawler->selectButton('Добавить')->form([
            'brand_link_form[linkType]' => 'website',
            'brand_link_form[linkUrl]'  => 'https://dup.example.com',
        ]));
        $this->assertResponseRedirects('/brand/links');

        $crawler = $client->request('GET', '/brand/links');
        $client->submit($crawler->selectButton('Добавить')->form([
            'brand_link_form[linkType]' => 'instagram',
            'brand_link_form[linkUrl]'  => 'https://dup.example.com',
        ]));
        $this->assertResponseRedirects('/brand/links');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->find(Brand::class, $brand->getId());
        $this->assertCount(1, $reloaded->getActiveLinks());
    }

    public function testNinthLinkIsRejected(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        for ($i = 1; $i <= 8; $i++) {
            $em->persist($this->newLink($brand, 'other', "https://link{$i}.example.com"));
        }
        $em->flush();
        $em->clear();

        $crawler = $client->request('GET', '/brand/links');
        $client->submit($crawler->selectButton('Добавить')->form([
            'brand_link_form[linkType]' => 'website',
            'brand_link_form[linkUrl]'  => 'https://link9.example.com',
        ]));
        $this->assertResponseRedirects('/brand/links');

        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->find(Brand::class, $brand->getId());
        $this->assertCount(8, $reloaded->getActiveLinks());
    }

    public function testDeleteIsSoftDelete(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $link = $this->newLink($brand, 'website', 'https://to-delete.example.com');
        $em->persist($link);
        $em->flush();
        $linkId = $link->getId();

        // Токен берём со страницы (crawler), а не из CSRF-сессии напрямую — после
        // завершения запроса request_stack пуст и SessionTokenStorage бросает исключение.
        $crawler = $client->request('GET', '/brand/links');
        $token   = $crawler->filter('form[action*="/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', '/brand/links/' . $linkId . '/delete', ['_token' => $token]);
        $this->assertResponseRedirects('/brand/links');

        $crawler = $client->request('GET', '/brand/links');
        $this->assertSelectorTextNotContains('body', 'to-delete.example.com');

        $client->request('GET', '/ru/brands/' . $brand->getSlug());
        $this->assertSelectorTextNotContains('body', 'to-delete.example.com');

        $em->clear();
        /** @var BrandLink $stillThere */
        $stillThere = $em->find(BrandLink::class, $linkId);
        $this->assertNotNull($stillThere, 'Физическая строка должна остаться (soft-delete)');
        $this->assertSame(Statuses::Deleted, $stillThere->getStatus());
    }

    /** Ссылка чужого бренда — прямая персистенция в обход ЛК (для IDOR-теста). */
    private function makeForeignLink(EntityManagerInterface $em, string $url): BrandLink
    {
        $foreignBrand = (new Brand())->setTitle('Foreign Brand')->setSlug('foreign-brand-' . uniqid());
        $em->persist($foreignBrand);

        $link = $this->newLink($foreignBrand, 'website', $url);
        $em->persist($link);
        $em->flush();

        return $link;
    }

    /** BrandLink с обязательным (DefaultFields) slug — как в enrichment-командах. */
    private function newLink(Brand $brand, string $type, string $url): BrandLink
    {
        return (new BrandLink())
            ->setBrand($brand)
            ->setLinkType($type)
            ->setLinkUrl($url)
            ->setSlug(substr(md5($type . $url), 0, 24));
    }
}
