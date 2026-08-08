<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Repository\BrandAttributeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findValuesByBrandAndName — источник категорий/материалов для слайдов-фактов
 * (SlideScriptComposer). Реальный Doctrine-запрос, не мок: убеждаемся, что фильтр по имени
 * атрибута и порядок (по id) реально работают, а не просто «похоже на правильный DQL».
 */
class BrandAttributeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BrandAttributeRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(BrandAttributeRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testReturnsOnlyValuesForRequestedNameInInsertionOrder(): void
    {
        $brand = (new Brand())->setTitle('Тест')->setSlug('test-attr-brand');
        $this->em->persist($brand);

        $this->em->persist((new BrandAttribute())->setBrand($brand)->setName(BrandAttribute::NAME_CATEGORY)->setValue('брюки'));
        $this->em->persist((new BrandAttribute())->setBrand($brand)->setName(BrandAttribute::NAME_MATERIAL)->setValue('хлопок'));
        $this->em->persist((new BrandAttribute())->setBrand($brand)->setName(BrandAttribute::NAME_CATEGORY)->setValue('футболки'));
        $this->em->flush();

        self::assertSame(['брюки', 'футболки'], $this->repo->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY));
        self::assertSame(['хлопок'], $this->repo->findValuesByBrandAndName($brand, BrandAttribute::NAME_MATERIAL));
    }

    public function testReturnsEmptyArrayWhenBrandHasNoSuchAttribute(): void
    {
        $brand = (new Brand())->setTitle('Тест 2')->setSlug('test-attr-brand-2');
        $this->em->persist($brand);
        $this->em->flush();

        self::assertSame([], $this->repo->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY));
    }
}
