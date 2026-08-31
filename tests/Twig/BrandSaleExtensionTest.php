<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Brand;
use App\Entity\PaymentProvider;
use App\Entity\SellerLegalEntity;
use App\Entity\SellerPaymentAccount;
use App\Twig\BrandSaleExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BrandSaleExtensionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BrandSaleExtension $extension;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        try {
            $this->em->getConnection()->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            self::markTestSkipped('Database is not available.');
        }
        $this->extension = self::getContainer()->get(BrandSaleExtension::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testReadyAccountAllowsSale(): void
    {
        $brand = $this->makeBrand('sale-ready');
        $this->makeReadyAccount($brand);

        self::assertTrue($this->extension->canSell($brand));
    }

    public function testNoLegalEntityBlocksSale(): void
    {
        $brand = $this->makeBrand('sale-no-legal-entity');

        self::assertFalse($this->extension->canSell($brand));
    }

    public function testNonPrimaryAccountBlocksSale(): void
    {
        $brand = $this->makeBrand('sale-non-primary');
        $this->makeReadyAccount($brand, primary: false);

        self::assertFalse($this->extension->canSell($brand));
    }

    public function testDisabledAccountBlocksSale(): void
    {
        $brand = $this->makeBrand('sale-disabled');
        $this->makeReadyAccount($brand, status: SellerPaymentAccount::STATUS_DISABLED);

        self::assertFalse($this->extension->canSell($brand));
    }

    public function testMissingSecretBlocksSale(): void
    {
        $brand = $this->makeBrand('sale-no-secret');
        $this->makeReadyAccount($brand, secretEncrypted: '');

        self::assertFalse($this->extension->canSell($brand));
    }

    private function makeBrand(string $slug): Brand
    {
        $brand = (new Brand())
            ->setTitle('Test Brand ' . $slug)
            ->setSlug($slug);
        $this->em->persist($brand);
        $this->em->flush();

        return $brand;
    }

    private function makeReadyAccount(
        Brand $brand,
        bool $primary = true,
        string $status = SellerPaymentAccount::STATUS_ACTIVE,
        string $secretEncrypted = 'enc-secret',
    ): void {
        $provider = $this->em->getRepository(PaymentProvider::class)->findOneBy(['code' => PaymentProvider::CODE_YOOKASSA]);
        if ($provider === null) {
            $provider = new PaymentProvider();
            $provider->setCode(PaymentProvider::CODE_YOOKASSA);
            $provider->setName('YooKassa');
            $this->em->persist($provider);
        }

        $legalEntity = (new SellerLegalEntity())
            ->setBrand($brand)
            ->setLegalName('ООО Тест')
            ->setStatus(SellerLegalEntity::STATUS_ACTIVE);
        $this->em->persist($legalEntity);

        $account = (new SellerPaymentAccount())
            ->setProvider($provider)
            ->setIsPrimary($primary)
            ->setStatus($status)
            ->setAccountRef('shop-123')
            ->setSecretEncrypted($secretEncrypted);
        $legalEntity->addPaymentAccount($account);
        $this->em->persist($account);

        $this->em->flush();
    }
}
