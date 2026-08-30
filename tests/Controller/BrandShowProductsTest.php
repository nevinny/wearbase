<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Публичная страница бренда: витрина реальных товаров (взамен createDemoProducts-заглушки).
 */
final class BrandShowProductsTest extends DatabaseDependentWebTestCase
{
    private array $brandIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->brandIds !== []) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            foreach ($this->brandIds as $id) {
                $brand = $em->find(Brand::class, $id);
                if ($brand !== null) {
                    foreach ($brand->getProducts() as $product) {
                        $em->remove($product);
                    }
                    $em->remove($brand);
                }
            }
            $em->flush();
            $this->brandIds = [];
        }
        parent::tearDown();
    }

    public function testActiveBrandShowsPublishedProductButHidesDisabled(): void
    {
        $brand = $this->makeBrand('brand-show-products-active');

        $em = $this->em();
        $active = (new Product())
            ->setTitle('Активный товар витрины')
            ->setBrand($brand)
            ->setStatus(Statuses::Active);
        $em->persist($active);

        $disabled = (new Product())
            ->setTitle('Скрытый товар витрины')
            ->setBrand($brand)
            ->setStatus(Statuses::Disabled);
        $em->persist($disabled);

        $em->flush();
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/brands/brand-show-products-active');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Активный товар витрины', $html);
        $this->assertStringNotContainsString('Скрытый товар витрины', $html);
    }

    public function testBrandWithoutProductsHasNoProductsBlockAndIsStill200(): void
    {
        $this->makeBrand('brand-show-products-empty');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/brands/brand-show-products-empty');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString('Товары бренда', $html);
    }

    private function makeBrand(string $slug): Brand
    {
        $em = $this->em();
        $brand = (new Brand())
            ->setTitle('Тестовый бренд витрины ' . $slug)
            ->setSlug($slug)
            ->setStatus(Statuses::Active);
        $em->persist($brand);
        $em->flush();

        $this->brandIds[] = $brand->getId();

        return $brand;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }
}
