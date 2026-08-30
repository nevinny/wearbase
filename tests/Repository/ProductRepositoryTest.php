<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProductRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ProductRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testFindSimilarReturnsProductsFromSameCategory(): void
    {
        $category = new ProductCategory();
        $category->setTitle('TestCat');
        $category->setSlug('test-cat');
        $this->em->persist($category);

        $brand = new Brand();
        $brand->setTitle('Test Brand');
        $brand->setSlug('test-brand');
        $this->em->persist($brand);

        $product = new Product();
        $product->setTitle('Reference');
        $product->setBrand($brand);
        $product->setCategory($category);
        $product->setStatus(Statuses::Active);
        $this->em->persist($product);

        $similar = new Product();
        $similar->setTitle('Similar One');
        $similar->setBrand($brand);
        $similar->setCategory($category);
        $similar->setStatus(Statuses::Active);
        $this->em->persist($similar);

        $otherCategory = new ProductCategory();
        $otherCategory->setTitle('OtherCat');
        $otherCategory->setSlug('other-cat');
        $this->em->persist($otherCategory);

        $unrelated = new Product();
        $unrelated->setTitle('Unrelated');
        $unrelated->setBrand($brand);
        $unrelated->setCategory($otherCategory);
        $unrelated->setStatus(Statuses::Active);
        $this->em->persist($unrelated);

        $this->em->flush();

        $result = $this->repo->findSimilar($product, 5);

        $this->assertCount(1, $result);
        $this->assertSame('Similar One', $result[0]->getTitle());
    }

    public function testFindForBrandPageOrdersProductsWithPhotoFirstAndExcludesDisabled(): void
    {
        $brand = new Brand();
        $brand->setTitle('Test Brand Page');
        $brand->setSlug('test-brand-page');
        $this->em->persist($brand);

        $withoutPhoto = new Product();
        $withoutPhoto->setTitle('Без фото');
        $withoutPhoto->setBrand($brand);
        $withoutPhoto->setStatus(Statuses::Active);
        $this->em->persist($withoutPhoto);

        $withPhoto = new Product();
        $withPhoto->setTitle('С фото');
        $withPhoto->setBrand($brand);
        $withPhoto->setStatus(Statuses::Active);
        $this->em->persist($withPhoto);

        $image = new \App\Entity\ProductImage();
        $image->setProduct($withPhoto);
        $image->setSlug('test-product-image');
        $image->setPreview('preview.jpg');
        $image->setIsMain(true);
        $this->em->persist($image);

        $disabled = new Product();
        $disabled->setTitle('Скрытый товар');
        $disabled->setBrand($brand);
        $disabled->setStatus(Statuses::Disabled);
        $this->em->persist($disabled);

        $this->em->flush();

        $result = $this->repo->findForBrandPage($brand);

        $this->assertCount(2, $result);
        $this->assertSame('С фото', $result[0]->getTitle());
        $this->assertSame('Без фото', $result[1]->getTitle());
    }
}
