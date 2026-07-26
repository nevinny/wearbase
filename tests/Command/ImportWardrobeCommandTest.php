<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Wardrobe;
use App\Entity\WardrobeItem;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportWardrobeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $jsonFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->jsonFile = tempnam(sys_get_temp_dir(), 'wardrobe-import-test-') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
        if ($this->jsonFile !== '' && is_file($this->jsonFile)) {
            unlink($this->jsonFile);
        }
        parent::tearDown();
    }

    public function testBatchImportCreatesExactlyOneDefaultWardrobe(): void
    {
        $email = 'wardrobe-import-' . uniqid() . '@test.local';
        $user = UserFactory::withEmail(self::getContainer(), $email);
        file_put_contents($this->jsonFile, json_encode([
            ['category' => 'Футболка', 'name' => 'Первая', 'productUrl' => 'https://example.com/1', 'size' => 'M'],
            ['category' => 'Брюки', 'name' => 'Вторая', 'productUrl' => 'https://example.com/2', 'size' => 'M'],
            ['category' => 'Обувь', 'name' => 'Третья', 'productUrl' => 'https://example.com/3', 'size' => '39'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $command = (new Application(self::$kernel))->find('app:wardrobe:import');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['file' => $this->jsonFile, '--user' => $email]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Создано 3, пропущено 0', $tester->getDisplay());
        self::assertSame(3, $this->entityManager->getRepository(WardrobeItem::class)->count(['user' => $user]));
        self::assertSame(1, $this->entityManager->getRepository(Wardrobe::class)->count(['owner' => $user, 'isDefault' => true]));
    }
}
