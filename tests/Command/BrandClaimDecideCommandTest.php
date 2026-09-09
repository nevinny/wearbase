<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\Tariff;
use App\Entity\User;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:brand:claim-decide — точная реплика BrandClaimAdminController::approve()/reject()
 * с консоли (для решений без логина в /admin).
 */
class BrandClaimDecideCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        // free-тариф: в test-БД не засеян миграцией (см. BrandClaimGrantIntegrationTest) — нужен
        // grantOwnership() → SubscriptionFactory::createFreeTrial().
        if (!$this->em->getRepository(Tariff::class)->findOneBy(['code' => Tariff::CODE_FREE])) {
            $tariff = (new Tariff())->setName('Free')->setCode(Tariff::CODE_FREE)->setTrialDays(30)->setMaxProducts(10);
            $this->em->persist($tariff);
            $this->em->flush();
        }
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testApproveGrantsOwnership(): void
    {
        $claim = $this->pendingClaim();

        $exit = $this->tester()->execute(['claimId' => $claim->getId(), 'decision' => 'approve']);

        $this->assertSame(Command::SUCCESS, $exit);

        $this->em->refresh($claim);
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus());

        $brandUser = self::getContainer()->get(BrandUserRepository::class)
            ->findOneBy(['brand' => $claim->getBrand(), 'user' => $claim->getUser()]);
        $this->assertNotNull($brandUser, 'должен появиться BrandUser owner');
        $this->assertSame(BrandUser::ROLE_OWNER, $brandUser->getRole());
        $this->assertContains('ROLE_BRAND_OWNER', $claim->getUser()->getRoles());
    }

    public function testRejectSetsRejectedAndReviewedAt(): void
    {
        $claim = $this->pendingClaim();

        $exit = $this->tester()->execute(['claimId' => $claim->getId(), 'decision' => 'reject', '--note' => 'Не подтверждён контакт']);

        $this->assertSame(Command::SUCCESS, $exit);

        $this->em->refresh($claim);
        $this->assertSame(BrandClaim::STATUS_REJECTED, $claim->getStatus());
        $this->assertSame('Не подтверждён контакт', $claim->getAdminNote());
        $this->assertNotNull($claim->getReviewedAt());

        $notification = $this->em->getRepository(Notification::class)->findOneBy(['recipient' => $claim->getUser()]);
        $this->assertNotNull($notification, 'заявитель должен получить уведомление об отказе');
        $this->assertStringContainsString('отклонена', (string) $notification->getTitle());
    }

    public function testRepeatedApproveOnProcessedClaimFailsWithoutChanges(): void
    {
        $claim = $this->pendingClaim();
        $this->tester()->execute(['claimId' => $claim->getId(), 'decision' => 'approve']);
        $this->em->refresh($claim);
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus());

        $before = $this->em->getRepository(BrandUser::class)->count(['brand' => $claim->getBrand()]);

        $exit = $this->tester()->execute(['claimId' => $claim->getId(), 'decision' => 'approve']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->em->refresh($claim);
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus(), 'повторный approve не должен ничего менять');

        $after = $this->em->getRepository(BrandUser::class)->count(['brand' => $claim->getBrand()]);
        $this->assertSame($before, $after, 'BrandUser не должен дублироваться');
    }

    public function testUnknownClaimReturnsFailure(): void
    {
        $exit = $this->tester()->execute(['claimId' => 999999999, 'decision' => 'approve']);
        $this->assertSame(Command::FAILURE, $exit);
    }

    public function testUnknownDecisionReturnsInvalid(): void
    {
        $claim = $this->pendingClaim();
        $exit  = $this->tester()->execute(['claimId' => $claim->getId(), 'decision' => 'maybe']);
        $this->assertSame(Command::INVALID, $exit);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:brand:claim-decide'));
    }

    private function pendingClaim(): BrandClaim
    {
        $user = new User();
        $user->setEmail('claimant-' . uniqid('', true) . '@example.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        $brand = new Brand();
        $brand->setTitle('Бренд ' . uniqid('', true));
        $brand->setSlug('brand-' . uniqid('', true));
        $this->em->persist($brand);

        $claim = new BrandClaim();
        $claim->setBrand($brand);
        $claim->setUser($user);
        $claim->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $this->em->persist($claim);

        $this->em->flush();

        return $claim;
    }
}
