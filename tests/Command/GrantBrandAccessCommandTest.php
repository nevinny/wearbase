<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ручная выдача доступа в ЛК бренда (app:brand:grant-access, sales_offer.md §11):
 * создаёт аккаунт с рабочим паролем, бренд-заготовку в статусе `new` (не в каталоге)
 * и связь владельца. Повторный запуск не плодит бренды — только меняет пароль.
 */
class GrantBrandAccessCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:brand:grant-access'));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testCreatesAccountBrandAndOwnerLink(): void
    {
        $email = 'grant-' . uniqid() . '@example.com';

        $this->tester->execute(['--email' => $email, '--password' => 'TempPass123', '--title' => 'Ручной Бренд']);

        $this->tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('TempPass123', $this->tester->getDisplay());

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertContains('ROLE_BRAND_MANAGER', $user->getRoles());
        $this->assertTrue($user->isEmailVerified(), 'Ручная выдача доступа считается подтверждённым email');
        $this->assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'TempPass123'),
            'Выданным паролем должно получаться войти',
        );

        $link = $this->em->getRepository(BrandUser::class)->findOneBy(['user' => $user]);
        $this->assertNotNull($link);
        $this->assertSame(BrandUser::ROLE_OWNER, $link->getRole());

        $brand = $link->getBrand();
        $this->assertSame('Ручной Бренд', $brand->getTitle());
        $this->assertSame(Statuses::New, $brand->getStatus(), 'Бренд-заготовка не должна попадать в каталог');
    }

    public function testDefaultTitleDerivedFromEmail(): void
    {
        $email = 'noname-' . uniqid() . '@example.com';

        $this->tester->execute(['--email' => $email]);

        $this->tester->assertCommandIsSuccessful();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $brand = $this->em->getRepository(BrandUser::class)->findOneBy(['user' => $user])->getBrand();
        $this->assertStringContainsString('Новый бренд', (string) $brand->getTitle());
    }

    public function testSecondRunOnlyResetsPassword(): void
    {
        $email = 'repeat-' . uniqid() . '@example.com';

        $this->tester->execute(['--email' => $email, '--password' => 'First123456']);
        $this->tester->execute(['--email' => $email, '--password' => 'Second12345']);

        $this->tester->assertCommandIsSuccessful();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertCount(1, $this->em->getRepository(BrandUser::class)->findBy(['user' => $user]), 'Второй бренд создаваться не должен');
        $this->assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'Second12345'),
        );
    }

    public function testInvalidEmailRejected(): void
    {
        $this->tester->execute(['--email' => 'not-an-email']);

        $this->assertSame(2, $this->tester->getStatusCode(), 'Некорректный email → INVALID');
        $this->assertSame(0, count($this->em->getRepository(Brand::class)->findBy(['title' => 'Новый бренд not-an-email'])));
    }
}
