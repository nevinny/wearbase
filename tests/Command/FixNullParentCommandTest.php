<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * parent = NULL → 0 (app:fix:null-parent) + дефолты сущностей.
 *
 * До admin-core v1.0.7 листинг админки фильтровал `entity.parent = 0`, и строки с NULL были
 * не видны: на проде так пряталось 3325 брендов из 3669. Бандл починен (условие корня теперь
 * `parent = 0 OR parent IS NULL`), но дефолт 0 держим — по нему пишутся фильтры и сортировки,
 * а команда остаётся ремонтным инструментом для строк, накопленных где-то ещё.
 */
class FixNullParentCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:fix:null-parent'));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testNewEntityGetsZeroParentByDefault(): void
    {
        $style = (new BrandStyle())->setTitle('Стиль ' . uniqid());
        $style->setSlug('style-' . uniqid());
        $this->em->persist($style);
        $this->em->flush();

        $this->assertSame(0, $style->getParent(), 'Дефолт трейта DefaultFields — 0');
    }

    public function testCommandFixesExistingNulls(): void
    {
        $style = $this->styleWithNullParent();

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $this->em->refresh($style);
        $this->assertSame(0, $style->getParent(), 'Команда должна заменить NULL на 0');
    }

    public function testDryRunChangesNothing(): void
    {
        $style = $this->styleWithNullParent();

        $this->tester->execute(['--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('dry-run', $this->tester->getDisplay());
        $this->em->refresh($style);
        $this->assertNull($style->getParent(), 'dry-run не должен ничего менять');
    }

    /** Загоняем строку в NULL напрямую, чтобы воспроизвести накопленное на проде состояние. */
    private function styleWithNullParent(): BrandStyle
    {
        $style = (new BrandStyle())->setTitle('Стиль ' . uniqid());
        $style->setSlug('style-' . uniqid());
        $this->em->persist($style);
        $this->em->flush();

        $this->em->createQuery('UPDATE App\Entity\BrandStyle s SET s.parent = NULL WHERE s.id = :id')
            ->setParameter('id', $style->getId())
            ->execute();
        $this->em->refresh($style);
        $this->assertNull($style->getParent());

        return $style;
    }

    /**
     * Brand объявляет `parent` сам, трейт DefaultFields в нём НЕ подключён. Детект обязан идти
     * по метаданным Doctrine — иначе самая большая таблица (3325 строк на проде) остаётся не
     * исправленной, как и было в первой версии фикса.
     */
    public function testBrandIsCoveredDespiteNotUsingTrait(): void
    {
        $brand = (new Brand())->setTitle('Бренд ' . uniqid());
        $brand->setSlug('brand-parent-' . uniqid());
        $this->em->persist($brand);
        $this->em->flush();

        $this->assertSame(0, $brand->getParent(), 'Brand объявляет parent сам — дефолт тоже 0');

        $this->em->createQuery('UPDATE App\Entity\Brand b SET b.parent = NULL WHERE b.id = :id')
            ->setParameter('id', $brand->getId())
            ->execute();
        $this->em->refresh($brand);
        $this->assertNull($brand->getParent());

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('brand', $this->tester->getDisplay());
        $this->em->refresh($brand);
        $this->assertSame(0, $brand->getParent());
    }
}
