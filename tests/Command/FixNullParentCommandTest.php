<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\BrandStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * parent = NULL → 0 (app:fix:null-parent) + предохранитель на prePersist.
 *
 * Листинг админки (DefaultCrudController) фильтрует `entity.parent = 0`, поэтому строки
 * с NULL в админке не видны: на проде так пряталось 3325 брендов из 3669.
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

    public function testNewEntityGetsZeroParentOnPersist(): void
    {
        $style = (new BrandStyle())->setTitle('Стиль ' . uniqid());
        $style->setSlug('style-' . uniqid());
        $this->em->persist($style);
        $this->em->flush();

        $this->assertSame(0, $style->getParent(), 'prePersist должен подставлять parent = 0');
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

    /** Обходим prePersist-предохранитель, чтобы получить строку с NULL как в проде. */
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
}
