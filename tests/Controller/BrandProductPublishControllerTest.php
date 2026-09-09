<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Тумблер публикации товара в ЛК бренда (/brand/products/{id}/publish).
 *
 * Регресс: `$product->getStatus() === 'active'` сравнивал enum со строкой (всегда false),
 * а `setStatus('active')` со строковым значением давал TypeError (500) при strict_types.
 */
final class BrandProductPublishControllerTest extends AuthenticatedWebTestCase
{
    private ?int $productId = null;
    private ?EntityManagerInterface $em = null;

    protected function tearDown(): void
    {
        if ($this->productId !== null && $this->em !== null && $this->em->isOpen()) {
            $product = $this->em->find(Product::class, $this->productId);
            if ($product !== null) {
                $this->em->remove($product);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    public function testToggleTwiceSwitchesActiveDisabledActive(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $product = (new Product())
            ->setTitle('Тестовый товар для тумблера публикации')
            ->setBrand($brand);
        $this->em->persist($product);
        $this->em->flush();
        $this->productId = $product->getId();

        // Дефолт трейта Status — Active, значит доступна кнопка «Снять с публикации».
        $crawler = $client->request('GET', '/brand/products');
        $this->assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Снять с публикации')->form());
        $this->assertResponseRedirects('/brand/products');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(Product::class, $this->productId);
        $this->assertSame(Statuses::Disabled, $reloaded->getStatus());

        // Повторный клик возвращает в active — регресс А (TypeError на строковом setStatus).
        $crawler = $client->request('GET', '/brand/products');
        $this->assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Опубликовать')->form());
        $this->assertResponseRedirects('/brand/products');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(Product::class, $this->productId);
        $this->assertSame(Statuses::Active, $reloaded->getStatus());
    }
}
