<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\ProductImage;
use PHPUnit\Framework\TestCase;

class ProductImageRelationTest extends TestCase
{
    public function testAddProductImageLinksBothSides(): void
    {
        $product = new Product();
        $image = new ProductImage();

        $product->addProductImage($image);

        $this->assertCount(1, $product->getProductImages());
        $this->assertSame($product, $image->getProduct());
    }

    public function testAddIsIdempotent(): void
    {
        $product = new Product();
        $image = new ProductImage();

        $product->addProductImage($image);
        $product->addProductImage($image);

        $this->assertCount(1, $product->getProductImages());
    }

    public function testRemoveProductImageUnlinks(): void
    {
        $product = new Product();
        $image = new ProductImage();
        $product->addProductImage($image);

        $product->removeProductImage($image);

        $this->assertCount(0, $product->getProductImages());
        $this->assertNull($image->getProduct());
    }
}
