<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\ProductIntentClick;
use App\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * POST /product/{uuid}/want — сигнал «Хочу купить» для брендов без приёма оплаты.
 * Публичный маршрут: гость должен мочь нажать без логина.
 */
final class ProductIntentControllerTest extends DatabaseDependentWebTestCase
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
            $em->getConnection()->executeStatement(
                'DELETE FROM product_intent_click WHERE brand_id IN (' . implode(',', $this->brandIds) . ')'
            );
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

    public function testGuestClickIsRecordedAndRedirectsWithFlash(): void
    {
        $product = $this->makeProduct('intent-guest');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $crawler = $client->request('GET', '/product/' . $product->getUuid());
        $token = $crawler->filter('form[action*="/want"] input[name="_token"]')->attr('value');

        $client->request('POST', '/product/' . $product->getUuid() . '/want', ['_token' => $token]);

        $this->assertResponseRedirects('/product/' . $product->getUuid());
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Спасибо — мы передали ваш интерес бренду');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->assertSame(1, $em->getRepository(ProductIntentClick::class)->count(['product' => $product]));
    }

    public function testInvalidCsrfTokenDoesNotRecordClick(): void
    {
        $product = $this->makeProduct('intent-bad-token');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('POST', '/product/' . $product->getUuid() . '/want', ['_token' => 'invalid']);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->assertSame(0, $em->getRepository(ProductIntentClick::class)->count(['product' => $product]));
    }

    private function makeProduct(string $slug): Product
    {
        $em = $this->em();
        $brand = (new Brand())
            ->setTitle('Test Brand ' . $slug)
            ->setSlug($slug)
            ->setStatus(Statuses::Active);
        $em->persist($brand);
        $em->flush();
        $this->brandIds[] = $brand->getId();

        $product = (new Product())
            ->setTitle('Test Product ' . $slug)
            ->setBrand($brand)
            ->setStatus(Statuses::Active);
        $em->persist($product);

        $variant = (new ProductVariant())
            ->setProduct($product)
            ->setPrice('1000.00')
            ->setStockQty(5)
            ->setStatus('active');
        $em->persist($variant);

        $em->flush();

        return $product;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }
}
