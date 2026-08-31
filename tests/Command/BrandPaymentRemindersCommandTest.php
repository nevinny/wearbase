<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\PaymentProvider;
use App\Entity\Product;
use App\Entity\SellerLegalEntity;
use App\Entity\SellerPaymentAccount;
use App\Entity\User;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:brand:payment-reminders — напоминания 1/3/7/14/30 день после публикации бренду
 * без настроенного приёма оплаты.
 */
final class BrandPaymentRemindersCommandTest extends KernelTestCase
{
    private const NOW = '2026-08-31T10:30:00+03:00';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        try {
            $this->em->getConnection()->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            self::markTestSkipped('Database is not available.');
        }
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testSendsOnMilestoneDayButNotOnOtherDays(): void
    {
        [$brand1, $owner1] = $this->makeCandidate('reminder-day1', daysAgo: 1);
        [$brand2, $owner2] = $this->makeCandidate('reminder-day2', daysAgo: 2);

        $this->execute();

        self::assertSame(1, $this->reminderCount($owner1));
        self::assertSame(0, $this->reminderCount($owner2));
    }

    public function testAllFiveMilestonesFire(): void
    {
        foreach ([1, 3, 7, 14, 30] as $day) {
            [, $owner] = $this->makeCandidate('reminder-milestone-' . $day, daysAgo: $day);
            $this->execute();
            self::assertSame(1, $this->reminderCount($owner), sprintf('день %d должен дать напоминание', $day));
        }
    }

    public function testRepeatRunDoesNotDuplicate(): void
    {
        [, $owner] = $this->makeCandidate('reminder-no-dup', daysAgo: 1);

        $this->execute();
        $this->execute();

        self::assertSame(1, $this->reminderCount($owner));
    }

    public function testConfiguredBrandIsSkipped(): void
    {
        [$brand, $owner] = $this->makeCandidate('reminder-configured', daysAgo: 1);
        $this->makeReadyAccount($brand);

        $this->execute();

        self::assertSame(0, $this->reminderCount($owner));
    }

    public function testBrandWithoutOwnerIsSkipped(): void
    {
        $this->makeCandidate('reminder-no-owner', daysAgo: 1, withOwner: false);

        $tester = $this->execute();
        self::assertSame(0, $this->em->getRepository(Notification::class)->count(['type' => Notification::TYPE_PAYMENT_REMINDER]));
        self::assertStringContainsString('Отправлено: 0', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        [, $owner] = $this->makeCandidate('reminder-dry-run', daysAgo: 1);

        $tester = $this->execute(['--dry-run' => true]);

        self::assertSame(0, $this->reminderCount($owner));
        self::assertStringContainsString('Будет отправлено: 1', $tester->getDisplay());
    }

    /**
     * @return array{0: Brand, 1: User}
     */
    private function makeCandidate(string $slug, int $daysAgo, bool $withOwner = true): array
    {
        $publishedAt = new \DateTime(self::NOW);
        $publishedAt->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $publishedAt->modify(sprintf('-%d days', $daysAgo));

        $brand = (new Brand())
            ->setTitle('Test Brand ' . $slug)
            ->setSlug($slug)
            ->setStatus(Statuses::Active)
            ->setPublishedAt($publishedAt);
        $this->em->persist($brand);

        $product = (new Product())
            ->setTitle('Test Product ' . $slug)
            ->setBrand($brand)
            ->setStatus(Statuses::Active);
        $this->em->persist($product);

        $owner = UserFactory::withEmail(self::getContainer(), $slug . '-' . bin2hex(random_bytes(6)) . '@test.local', ['ROLE_BRAND_OWNER']);

        if ($withOwner) {
            $link = (new BrandUser())
                ->setUser($owner)
                ->setBrand($brand)
                ->setRole(BrandUser::ROLE_OWNER);
            $this->em->persist($link);
        }

        $this->em->flush();

        return [$brand, $owner];
    }

    private function makeReadyAccount(Brand $brand): void
    {
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
            ->setIsPrimary(true)
            ->setStatus(SellerPaymentAccount::STATUS_ACTIVE)
            ->setAccountRef('shop-123')
            ->setSecretEncrypted('enc-secret');
        $legalEntity->addPaymentAccount($account);
        $this->em->persist($account);

        $this->em->flush();
    }

    private function reminderCount(User $owner): int
    {
        return $this->em->getRepository(Notification::class)->count([
            'recipient' => $owner,
            'type' => Notification::TYPE_PAYMENT_REMINDER,
        ]);
    }

    private function execute(array $options = []): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:brand:payment-reminders');
        $tester = new CommandTester($command);
        $tester->execute($options + ['--now' => self::NOW]);

        return $tester;
    }
}
