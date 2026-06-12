<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\SellerLegalEntity;
use App\Repository\SellerLegalEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SellerLegalEntityRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SellerLegalEntityRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(SellerLegalEntityRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function brand(string $slug): Brand
    {
        $brand = new Brand();
        $brand->setTitle($slug);
        $brand->setSlug($slug . '-' . uniqid());
        $this->em->persist($brand);
        return $brand;
    }

    private function legalEntity(Brand $brand, string $status, ?string $effectiveTo = null): SellerLegalEntity
    {
        $e = new SellerLegalEntity();
        $e->setBrand($brand);
        $e->setLegalName('LE ' . $brand->getTitle());
        $e->setStatus($status);
        if ($effectiveTo !== null) {
            $e->setEffectiveTo(new \DateTimeImmutable($effectiveTo));
        }
        $this->em->persist($e);
        return $e;
    }

    public function testReturnsActiveEntityOfRequestedBrand(): void
    {
        $brandA = $this->brand('brand-a');
        $brandB = $this->brand('brand-b');
        $entityA = $this->legalEntity($brandA, SellerLegalEntity::STATUS_ACTIVE);
        $this->legalEntity($brandB, SellerLegalEntity::STATUS_ACTIVE);
        $this->em->flush();

        $found = $this->repo->findActiveForBrand($brandA);

        $this->assertNotNull($found);
        $this->assertSame($entityA->getId(), $found->getId());
        $this->assertSame($brandA->getId(), $found->getBrand()->getId(), 'Не должно утекать юр.лицо другого бренда');
    }

    public function testReturnsNullWhenBrandHasNoActiveEntityEvenIfOthersDo(): void
    {
        // Регрессия OR-precedence: у A только архив, у B — активное.
        $brandA = $this->brand('brand-a');
        $brandB = $this->brand('brand-b');
        $this->legalEntity($brandA, SellerLegalEntity::STATUS_ARCHIVED);
        $this->legalEntity($brandB, SellerLegalEntity::STATUS_ACTIVE);
        $this->em->flush();

        $this->assertNull($this->repo->findActiveForBrand($brandA));
    }

    public function testExpiredEntityIsNotReturned(): void
    {
        $brand = $this->brand('brand-c');
        $this->legalEntity($brand, SellerLegalEntity::STATUS_ACTIVE, '2000-01-01');
        $this->em->flush();

        $this->assertNull($this->repo->findActiveForBrand($brand));
    }
}
