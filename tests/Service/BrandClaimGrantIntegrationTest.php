<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandUser;
use App\Entity\Tariff;
use App\Entity\User;
use App\Repository\BrandUserRepository;
use App\Repository\SubscriptionRepository;
use App\Service\BrandClaimService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Реальное выполнение grantOwnership против БД (happy path):
 * verify → создаётся BrandUser(owner) + роли + free-trial подписка.
 *
 * Изоляция: всё в транзакции с rollback. Запуск с MAILER_DSN=null://null,
 * чтобы dispatch не пытался реально отправить письмо.
 */
class BrandClaimGrantIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testGrantOwnershipCreatesBrandUserRolesAndSubscription(): void
    {
        // free-тариф: в test-БД не засеян миграцией; в dev-БД уже есть (code unique)
        $tariff = $this->em->getRepository(Tariff::class)->findOneBy(['code' => Tariff::CODE_FREE]);
        if (!$tariff) {
            $tariff = (new Tariff())->setName('Free')->setCode(Tariff::CODE_FREE)->setTrialDays(30)->setMaxProducts(10);
            $this->em->persist($tariff);
        }

        $user = (new User())->setEmail('owner@test.local')->setPassword('x')->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        $brand = (new Brand())->setTitle('Grant Test Brand')->setSlug('grant-test-brand');
        $this->em->persist($brand);

        $claim = (new BrandClaim())->setBrand($brand)->setUser($user)->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $this->em->persist($claim);
        $this->em->flush();

        // — выполняем реально —
        self::getContainer()->get(BrandClaimService::class)
            ->grantOwnership($claim, null, 'email_code');

        // BrandUser owner
        $brandUser = self::getContainer()->get(BrandUserRepository::class)
            ->findOneBy(['brand' => $brand, 'user' => $user]);
        $this->assertNotNull($brandUser, 'создан BrandUser');
        $this->assertSame(BrandUser::ROLE_OWNER, $brandUser->getRole());

        // Роли пользователя
        $this->assertContains('ROLE_BRAND_OWNER', $user->getRoles());
        $this->assertContains('ROLE_BRAND_MANAGER', $user->getRoles());

        // Подписка free-trial
        $sub = self::getContainer()->get(SubscriptionRepository::class)->findActiveByBrand($brand);
        $this->assertNotNull($sub, 'создана free-trial подписка');
        $this->assertSame(Tariff::CODE_FREE, $sub->getTariff()->getCode());

        // Статус заявки
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus());
        $this->assertSame('email_code', $claim->getVerifiedVia());
    }
}
