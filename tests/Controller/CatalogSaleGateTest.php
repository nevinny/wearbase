<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\PaymentProvider;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SellerLegalEntity;
use App\Entity\SellerPaymentAccount;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Гейт продажи на карточке товара (templates/catalog/show.html.twig):
 * бренд без настроенного приёма оплаты видит «Хочу купить» вместо «В корзину»;
 * «Нет в наличии» приоритетнее гейта продажи.
 */
final class CatalogSaleGateTest extends DatabaseDependentWebTestCase
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

    public function testBrandWithoutPaymentSetupShowsWantToBuyInsteadOfCart(): void
    {
        $em = $this->em();
        $brand = $this->makeBrand('sale-gate-not-ready');
        $product = $this->makeProduct($brand, 'sale-gate-not-ready', inStock: true);
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/product/' . $product->getUuid());

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Хочу купить', $html);
        $this->assertStringNotContainsString('В корзину', $html);
    }

    public function testBrandWithPaymentSetupShowsCartButton(): void
    {
        $em = $this->em();
        $brand = $this->makeBrand('sale-gate-ready');
        $this->makeReadyAccount($em, $brand);
        $product = $this->makeProduct($brand, 'sale-gate-ready', inStock: true);
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/product/' . $product->getUuid());

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('В корзину', $html);
        $this->assertStringNotContainsString('Хочу купить', $html);
    }

    public function testOutOfStockShowsUnavailableRegardlessOfPaymentSetup(): void
    {
        $product = $this->makeProduct($this->makeBrand('sale-gate-oos'), 'sale-gate-oos', inStock: false);
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/product/' . $product->getUuid());

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Нет в наличии', $html);
        $this->assertStringNotContainsString('Хочу купить', $html);
        $this->assertStringNotContainsString('В корзину', $html);
    }

    private function makeBrand(string $slug): Brand
    {
        $em = $this->em();
        $brand = (new Brand())
            ->setTitle('Test Brand ' . $slug)
            ->setSlug($slug)
            ->setStatus(Statuses::Active);
        $em->persist($brand);
        $em->flush();

        $this->brandIds[] = $brand->getId();

        return $brand;
    }

    private function makeProduct(Brand $brand, string $slug, bool $inStock): Product
    {
        $em = $this->em();
        $product = (new Product())
            ->setTitle('Test Product ' . $slug)
            ->setBrand($brand)
            ->setStatus(Statuses::Active);
        $em->persist($product);

        $variant = (new ProductVariant())
            ->setProduct($product)
            ->setPrice('1000.00')
            ->setStockQty($inStock ? 5 : 0)
            ->setStatus('active');
        $em->persist($variant);

        $em->flush();

        return $product;
    }

    private function makeReadyAccount(EntityManagerInterface $em, Brand $brand): void
    {
        $provider = $em->getRepository(PaymentProvider::class)->findOneBy(['code' => PaymentProvider::CODE_YOOKASSA]);
        if ($provider === null) {
            $provider = new PaymentProvider();
            $provider->setCode(PaymentProvider::CODE_YOOKASSA);
            $provider->setName('YooKassa');
            $em->persist($provider);
        }

        $legalEntity = (new SellerLegalEntity())
            ->setBrand($brand)
            ->setLegalName('ООО Тест')
            ->setStatus(SellerLegalEntity::STATUS_ACTIVE);
        $em->persist($legalEntity);

        $account = (new SellerPaymentAccount())
            ->setProvider($provider)
            ->setIsPrimary(true)
            ->setStatus(SellerPaymentAccount::STATUS_ACTIVE)
            ->setAccountRef('shop-123')
            ->setSecretEncrypted('enc-secret');
        $legalEntity->addPaymentAccount($account);
        $em->persist($account);

        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }
}
