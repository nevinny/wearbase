<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:brand:merge — склейка дубля бренда в выжившую карточку. */
class BrandMergeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testMovesProductsAndMembersDedupesSharedAndMarksDuplicate(): void
    {
        [$dup, $surv] = [$this->brand(), $this->brand()];
        $shared = $this->user();
        $onlyDup = $this->user();
        $this->member($dup, $shared);
        $this->member($dup, $onlyDup, BrandUser::ROLE_MANAGER);
        $this->member($surv, $shared);
        $product = $this->product($dup, 'p1');
        $this->em->flush();
        $dupId = $dup->getId();

        $exit = $this->merge($dup, $surv);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        $dup  = $this->em->find(Brand::class, $dupId);
        $surv = $this->em->find(Brand::class, $surv->getId());

        $this->assertSame($surv->getId(), $this->em->find(Product::class, $product->getId())->getBrand()->getId());
        $members = $this->em->getRepository(BrandUser::class);
        $this->assertSame(0, $members->count(['brand' => $dup]), 'у дубля не осталось участников');
        $this->assertSame(2, $members->count(['brand' => $surv]), 'общий участник не задвоен, второй перенесён');
        $this->assertSame($surv->getId(), $dup->getMergedInto()?->getId());
        $this->assertSame(Statuses::Deleted, $dup->getStatus());
    }

    public function testSecondRunIsIdempotentNoop(): void
    {
        [$dup, $surv] = [$this->brand(), $this->brand()];
        $this->product($dup, 'p1');
        $this->em->flush();

        $this->assertSame(Command::SUCCESS, $this->merge($dup, $surv));
        $tester = $this->tester();
        $exit = $tester->execute(['duplicateId' => $dup->getId(), 'survivorId' => $surv->getId()]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('уже склеен', $tester->getDisplay());
    }

    public function testSelfMergeRejected(): void
    {
        $b = $this->brand();
        $this->em->flush();
        $this->assertSame(Command::INVALID, $this->merge($b, $b));
    }

    public function testChainRejected(): void
    {
        [$a, $b, $c] = [$this->brand(), $this->brand(), $this->brand()];
        $this->em->flush();
        $this->assertSame(Command::SUCCESS, $this->merge($b, $c));
        // c — выживший для b; склеить a в b (b уже склеен) нельзя
        $this->assertSame(Command::FAILURE, $this->merge($a, $b));
        // b склеен с c, а не с a
        $this->assertSame(Command::FAILURE, $this->merge($b, $a));
    }

    public function testDryRunChangesNothing(): void
    {
        [$dup, $surv] = [$this->brand(), $this->brand()];
        $product = $this->product($dup, 'p1');
        $this->member($dup, $this->user());
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['duplicateId' => $dup->getId(), 'survivorId' => $surv->getId(), '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('dry-run', $tester->getDisplay());
        $this->em->clear();
        $this->assertSame($dup->getId(), $this->em->find(Product::class, $product->getId())->getBrand()->getId());
        $reloaded = $this->em->find(Brand::class, $dup->getId());
        $this->assertNull($reloaded->getMergedInto());
        $this->assertNotSame(Statuses::Deleted, $reloaded->getStatus());
    }

    public function testProductSlugConflictLeftOnDuplicate(): void
    {
        [$dup, $surv] = [$this->brand(), $this->brand()];
        $this->product($surv, 'same');
        $conflict = $this->product($dup, 'same');
        $this->em->flush();

        $this->assertSame(Command::SUCCESS, $this->merge($dup, $surv));
        $this->em->clear();
        $this->assertSame($dup->getId(), $this->em->find(Product::class, $conflict->getId())->getBrand()->getId());
    }

    private function merge(Brand $dup, Brand $surv): int
    {
        return $this->tester()->execute(['duplicateId' => $dup->getId(), 'survivorId' => $surv->getId()]);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:brand:merge'));
    }

    private function brand(): Brand
    {
        $b = new Brand();
        $b->setTitle('Бренд ' . uniqid('', true));
        $b->setSlug('brand-' . uniqid('', true));
        $this->em->persist($b);
        return $b;
    }

    private function user(): User
    {
        $u = new User();
        $u->setEmail('u-' . uniqid('', true) . '@example.com');
        $u->setPassword('hashed');
        $u->setRoles(['ROLE_USER']);
        $this->em->persist($u);
        return $u;
    }

    private function member(Brand $b, User $u, string $role = BrandUser::ROLE_OWNER): void
    {
        $m = (new BrandUser())->setBrand($b)->setUser($u)->setRole($role);
        $this->em->persist($m);
    }

    private function product(Brand $b, string $slug): Product
    {
        $p = new Product();
        $p->setTitle('Товар ' . $slug)->setSlug($slug)->setBrand($b);
        $this->em->persist($p);
        return $p;
    }
}
