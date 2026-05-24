<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProductTest extends KernelTestCase
{
    public function testSetAndGetCharacteristics(): void
    {
        $product = new Product();
        
        $product->setMaterial('Хлопок 100%');
        $product->setComposition('95% хлопок, 5% эластан');
        $product->setCareInstructions('Машинная стирка до 30°C');
        $product->setCountryOfOrigin('Россия');
        $product->setManufacturer('ООО «Бренд»');

        $this->assertSame('Хлопок 100%', $product->getMaterial());
        $this->assertSame('95% хлопок, 5% эластан', $product->getComposition());
        $this->assertSame('Машинная стирка до 30°C', $product->getCareInstructions());
        $this->assertSame('Россия', $product->getCountryOfOrigin());
        $this->assertSame('ООО «Бренд»', $product->getManufacturer());
    }
}
