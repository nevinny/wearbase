<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:brand:moderation-backfill — подчистка исторических self-register брендов, заведённых
 * до того, как RegisterController стал класть строку BrandModeration при регистрации (PR #69).
 */
class ModerationBackfillCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:brand:moderation-backfill'));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testOwnerBrandWithoutRowGetsQueued(): void
    {
        $brand = $this->ownerBrand();

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $moderation = $this->em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        $this->assertNotNull($moderation, 'Строка очереди должна быть создана');
        $this->assertSame(BrandModeration::STATUS_QUEUED, $moderation->getStatus());
        $this->assertSame(BrandModeration::SOURCE_SELF_REGISTER, $moderation->getSource());
    }

    public function testSecondRunIsIdempotent(): void
    {
        $brand = $this->ownerBrand();

        $this->tester->execute([]);
        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertCount(
            1,
            $this->em->getRepository(BrandModeration::class)->findBy(['brand' => $brand]),
            'Повторный прогон не должен плодить строки',
        );
    }

    public function testCatalogBrandWithoutOwnerIsSkipped(): void
    {
        $brand = (new Brand())->setTitle('Каталожный бренд ' . uniqid());
        $brand->setSlug('catalog-' . uniqid());
        $this->em->persist($brand);
        $this->em->flush();

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertNull(
            $this->em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]),
            'У бренда без владельца строки очереди быть не должно',
        );
    }

    public function testDeletedBrandIsSkipped(): void
    {
        $brand = $this->ownerBrand();
        $brand->setStatus(Statuses::Deleted);
        $this->em->flush();

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertNull(
            $this->em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]),
            'Удалённый бренд не должен попадать в очередь',
        );
    }

    public function testDryRunWritesNothing(): void
    {
        $brand = $this->ownerBrand();

        $this->tester->execute(['--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('dry-run', $this->tester->getDisplay());
        $this->assertNull(
            $this->em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]),
            'dry-run не должен ничего писать',
        );
    }

    /** Создаёт бренд с владельцем (self-register), как это делает RegisterController. */
    private function ownerBrand(): Brand
    {
        $user = new User();
        $user->setEmail('owner-' . uniqid() . '@example.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_BRAND_MANAGER']);
        $this->em->persist($user);

        $brand = new Brand();
        $brand->setTitle('Самрег бренд ' . uniqid());
        $brand->setSlug('self-reg-' . uniqid());
        $brand->setStatus(Statuses::New);
        $this->em->persist($brand);

        $brandUser = new BrandUser();
        $brandUser->setUser($user);
        $brandUser->setBrand($brand);
        $brandUser->setRole(BrandUser::ROLE_OWNER);
        $this->em->persist($brandUser);

        $this->em->flush();

        return $brand;
    }
}
