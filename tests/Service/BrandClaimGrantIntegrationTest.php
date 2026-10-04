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

    /**
     * Находка 2 (docs/brand_claim_review.md): админский approve звал grantOwnership()
     * напрямую, минуя brandHasOtherOwner(), и молча заводил ВТОРОГО владельца с полными
     * правами. Теперь гард внутри самого grantOwnership().
     */
    public function testGrantOwnershipRefusesWhenBrandAlreadyHasAnotherOwner(): void
    {
        $this->seedFreeTariff();

        $first  = (new User())->setEmail('first-owner@test.local')->setPassword('x')->setRoles(['ROLE_USER']);
        $second = (new User())->setEmail('second-owner@test.local')->setPassword('x')->setRoles(['ROLE_USER']);
        $brand  = (new Brand())->setTitle('Contested Brand')->setSlug('contested-brand');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($brand);

        $firstClaim = (new BrandClaim())->setBrand($brand)->setUser($first)->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $this->em->persist($firstClaim);
        $this->em->flush();

        $service = self::getContainer()->get(BrandClaimService::class);
        $service->grantOwnership($firstClaim, null, 'email_code');

        $secondClaim = (new BrandClaim())->setBrand($brand)->setUser($second)->setMethod(BrandClaim::METHOD_MANUAL);
        $this->em->persist($secondClaim);
        $this->em->flush();

        $this->expectException(\DomainException::class);

        try {
            $service->grantOwnership($secondClaim, null, 'admin');
        } finally {
            $owners = self::getContainer()->get(BrandUserRepository::class)
                ->findBy(['brand' => $brand, 'role' => BrandUser::ROLE_OWNER]);
            $this->assertCount(1, $owners, 'второй владелец не создан');
            $this->assertSame($first, $owners[0]->getUser());
            $this->assertSame(BrandClaim::STATUS_PENDING, $secondClaim->getStatus(), 'заявка осталась неодобренной');
        }
    }

    /**
     * Находка 3: reject() нигде не сверял текущий статус — одобренную заявку можно было
     * «отклонить», и она уезжала в rejected, тогда как BrandUser(owner), роли и подписка
     * оставались. Права и журнал расходились.
     */
    public function testRejectDoesNotTouchAlreadyApprovedClaim(): void
    {
        $this->seedFreeTariff();

        $user  = (new User())->setEmail('approved-owner@test.local')->setPassword('x')->setRoles(['ROLE_USER']);
        $brand = (new Brand())->setTitle('Approved Brand')->setSlug('approved-brand');
        $this->em->persist($user);
        $this->em->persist($brand);

        $claim = (new BrandClaim())->setBrand($brand)->setUser($user)->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $this->em->persist($claim);
        $this->em->flush();

        $service = self::getContainer()->get(BrandClaimService::class);
        $service->grantOwnership($claim, null, 'email_code');
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus());

        $this->assertFalse($service->reject($claim, null, 'передумали'), 'reject отказался трогать обработанную заявку');
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus(), 'статус не перезаписан');
        $this->assertNotSame('передумали', $claim->getAdminNote(), 'заметка не перезаписана');

        $brandUser = self::getContainer()->get(BrandUserRepository::class)
            ->findOneBy(['brand' => $brand, 'user' => $user]);
        $this->assertNotNull($brandUser, 'владение осталось на месте');
    }

    /** reject() по живой заявке по-прежнему работает и проставляет журнал. */
    public function testRejectMarksPendingClaimAndRecordsReviewer(): void
    {
        $user  = (new User())->setEmail('rejected-user@test.local')->setPassword('x')->setRoles(['ROLE_USER']);
        $brand = (new Brand())->setTitle('Rejected Brand')->setSlug('rejected-brand');
        $this->em->persist($user);
        $this->em->persist($brand);

        $claim = (new BrandClaim())->setBrand($brand)->setUser($user)->setMethod(BrandClaim::METHOD_MANUAL);
        $this->em->persist($claim);
        $this->em->flush();

        $this->assertTrue(self::getContainer()->get(BrandClaimService::class)->reject($claim, null, 'нет доказательств'));
        $this->assertSame(BrandClaim::STATUS_REJECTED, $claim->getStatus());
        $this->assertSame('нет доказательств', $claim->getAdminNote());
        $this->assertNotNull($claim->getReviewedAt());
    }

    /** free-тариф в test-БД миграцией не засеян — заводим при необходимости. */
    private function seedFreeTariff(): void
    {
        if (!$this->em->getRepository(Tariff::class)->findOneBy(['code' => Tariff::CODE_FREE])) {
            $this->em->persist(
                (new Tariff())->setName('Free')->setCode(Tariff::CODE_FREE)->setTrialDays(30)->setMaxProducts(10)
            );
        }
    }
}
