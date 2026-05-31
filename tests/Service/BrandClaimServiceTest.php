<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandLink;
use App\Entity\BrandUser;
use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use App\Service\BrandClaimService;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class BrandClaimServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private BrandUserRepository $brandUserRepo;
    private SubscriptionFactory $subscriptionFactory;
    private NotificationDispatcher $notifier;
    private EmailNotifier $emailNotifier;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->brandUserRepo = $this->createMock(BrandUserRepository::class);
        $this->subscriptionFactory = $this->createMock(SubscriptionFactory::class);
        $this->notifier = $this->createMock(NotificationDispatcher::class);
        $this->emailNotifier = $this->createMock(EmailNotifier::class);
    }

    private function service(bool $autoEmail = true, bool $autoVk = true): BrandClaimService
    {
        return new BrandClaimService(
            $this->em,
            $this->brandUserRepo,
            $this->subscriptionFactory,
            $this->notifier,
            $this->emailNotifier,
            $autoEmail,
            $autoVk,
        );
    }

    private function brandWithVkLink(string $url): Brand
    {
        $brand = new Brand();
        $link = (new BrandLink())->setLinkUrl($url);
        $brand->addLink($link);
        return $brand;
    }

    // ── availableMethods / brandVkGroup ────────────────────────────────────

    public function testVkGroupDetectedByUrlHostNotLinkType(): void
    {
        // link_type намеренно не задан — детект по URL
        $brand = $this->brandWithVkLink('https://vk.com/wahhid');
        $this->assertSame('wahhid', $this->service()->brandVkGroup($brand));
        $this->assertContains(BrandClaim::METHOD_VK_ADMIN, $this->service()->availableMethods($brand));
    }

    public function testEmailMethodOfferedOnlyWhenBrandHasEmail(): void
    {
        $withEmail = (new Brand())->setEmail('brand@example.com');
        $this->assertContains(BrandClaim::METHOD_EMAIL_CODE, $this->service()->availableMethods($withEmail));

        $noEmail = new Brand();
        $this->assertNotContains(BrandClaim::METHOD_EMAIL_CODE, $this->service()->availableMethods($noEmail));
    }

    public function testIsAutoGrantHonoursFlags(): void
    {
        $svc = $this->service(autoEmail: true, autoVk: false);
        $this->assertTrue($svc->isAutoGrant(BrandClaim::METHOD_EMAIL_CODE));
        $this->assertFalse($svc->isAutoGrant(BrandClaim::METHOD_VK_ADMIN));
        $this->assertFalse($svc->isAutoGrant(BrandClaim::METHOD_MANUAL));
    }

    // ── brandHasOtherOwner ─────────────────────────────────────────────────

    public function testBrandHasOtherOwnerTrueForDifferentUser(): void
    {
        $brand = new Brand();
        $me = new User();
        $other = new User();
        $ownerRow = (new BrandUser())->setBrand($brand)->setUser($other)->setRole(BrandUser::ROLE_OWNER);

        $this->brandUserRepo->method('findBy')->willReturn([$ownerRow]);
        $this->assertTrue($this->service()->brandHasOtherOwner($brand, $me));
    }

    public function testBrandHasOtherOwnerFalseWhenOnlySelf(): void
    {
        $brand = new Brand();
        $me = new User();
        $ownerRow = (new BrandUser())->setBrand($brand)->setUser($me)->setRole(BrandUser::ROLE_OWNER);

        $this->brandUserRepo->method('findBy')->willReturn([$ownerRow]);
        $this->assertFalse($this->service()->brandHasOtherOwner($brand, $me));
    }

    // ── email code ─────────────────────────────────────────────────────────

    public function testStartEmailCodeNoEmail(): void
    {
        $claim = (new BrandClaim())->setBrand(new Brand());
        $this->assertSame('no_email', $this->service()->startEmailCode($claim));
    }

    public function testStartEmailCodeSendsAndStoresCode(): void
    {
        $brand = (new Brand())->setEmail('brand@example.com');
        $claim = (new BrandClaim())->setBrand($brand);

        $this->emailNotifier->expects($this->once())->method('send')
            ->with('brand@example.com', $this->anything(), 'brand_claim_code', $this->anything());

        $this->assertSame('sent', $this->service()->startEmailCode($claim));
        $this->assertSame(BrandClaim::METHOD_EMAIL_CODE, $claim->getMethod());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $claim->getVerificationCode());
        $this->assertSame(1, $claim->getCodeSends());
        $this->assertNotNull($claim->getCodeExpiresAt());
    }

    public function testStartEmailCodeCooldown(): void
    {
        $brand = (new Brand())->setEmail('brand@example.com');
        $claim = (new BrandClaim())->setBrand($brand);
        $claim->setCodeSentAt(new \DateTimeImmutable()); // только что отправлен

        $this->emailNotifier->expects($this->never())->method('send');
        $this->assertSame('cooldown', $this->service()->startEmailCode($claim));
    }

    public function testStartEmailCodeSendLimit(): void
    {
        $brand = (new Brand())->setEmail('brand@example.com');
        $claim = (new BrandClaim())->setBrand($brand);
        $claim->setCodeSends(5);
        $claim->setCodeSentAt(new \DateTimeImmutable('-1 hour')); // cooldown прошёл

        $this->emailNotifier->expects($this->never())->method('send');
        $this->assertSame('limit', $this->service()->startEmailCode($claim));
    }

    public function testCheckEmailCodeMismatchThenOk(): void
    {
        $claim = (new BrandClaim())->setBrand((new Brand())->setEmail('b@e.com'));
        $claim->setVerificationCode('123456');
        $claim->setCodeExpiresAt(new \DateTimeImmutable('+10 minutes'));

        $svc = $this->service();
        $this->assertSame('mismatch', $svc->checkEmailCode($claim, '000000'));
        $this->assertSame(1, $claim->getCodeAttempts());

        $this->assertSame('ok', $svc->checkEmailCode($claim, '123456'));
        $this->assertNull($claim->getVerificationCode(), 'код обнуляется после успеха');
    }

    public function testCheckEmailCodeExpired(): void
    {
        $claim = (new BrandClaim())->setBrand(new Brand());
        $claim->setVerificationCode('123456');
        $claim->setCodeExpiresAt(new \DateTimeImmutable('-1 minute'));

        $this->assertSame('expired', $this->service()->checkEmailCode($claim, '123456'));
    }

    public function testCheckEmailCodeTooManyAttempts(): void
    {
        $claim = (new BrandClaim())->setBrand(new Brand());
        $claim->setVerificationCode('123456');
        $claim->setCodeExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $claim->setCodeAttempts(5);

        $this->assertSame('too_many', $this->service()->checkEmailCode($claim, '123456'));
    }

    public function testCheckEmailCodeNoCode(): void
    {
        $claim = (new BrandClaim())->setBrand(new Brand());
        $this->assertSame('no_code', $this->service()->checkEmailCode($claim, '123456'));
    }
}
