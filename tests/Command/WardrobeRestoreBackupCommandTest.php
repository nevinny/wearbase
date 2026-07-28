<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Tests\Controller\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Перенос гардероба между инсталляциями (app:wardrobe:restore-backup). Проверяем
 * контракт из TECHNICAL_SPEC пакета переноса: восстановление полей, категории по code,
 * схлопывание legacy+галерея, идемпотентность и — главное — что любая невалидность
 * останавливает импорт ЦЕЛИКОМ, а не создаёт половину чужого гардероба.
 */
class WardrobeRestoreBackupCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $tmpDir;
    /** Общая на прогон SQLite: без токена тесты находят вещи друг друга. */
    private string $token;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->token  = bin2hex(random_bytes(4));
        $this->tmpDir = sys_get_temp_dir() . '/wardrobe-restore-' . $this->token;
        mkdir($this->tmpDir . '/photos/wi/ld', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    /** @param array<string,mixed> $overrides */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'source_item_id' => 101,
            'owner'          => ['source_user_id' => 59, 'name' => 'Анна'],
            'original_owner' => null,
            'item_no'        => 1,
            'name'           => 'Рубашка ' . $this->token,
            'category'       => ['code' => null, 'name' => 'Рубашка', 'parent' => null],
            'brand'          => 'Stradivarius',
            'color'          => 'голубой',
            'size'           => 'L',
            'material'       => 'хлопок',
            'country_of_origin' => 'Индия',
            'season'         => 'demi',
            'styles'         => [],
            'price'          => '2990.00',
            'purchased_at'   => '2026-05-01T00:00:00+00:00',
            'purchase_reason' => 'в офис',
            'love_at_first_sight' => 'yes',
            'care'           => 'стирка 30',
            'pros'           => 'мягкая',
            'cons'           => 'мнётся',
            'verdict'        => 'оставить',
            'notes'          => '100% хлопок',
            'product_url'    => 'https://example.test/item',
            'completion_status' => WardrobeItem::COMPLETION_BASIC,
            'item_status'    => WardrobeItem::ITEM_ACTIVE,
            'wear_status'    => WardrobeItem::WEAR_ACTIVE,
            'source'         => 'import',
            'photos'         => [],
            'transfers'      => [],
            'created_at'     => '2026-07-23T21:02:02+00:00',
            'updated_at'     => null,
        ], $overrides);
    }

    /** @param list<array<string,mixed>> $items */
    private function writeBackup(array $items, int $version = 1, string $format = 'wearbase.wardrobe'): string
    {
        $path = $this->tmpDir . '/backup.json';
        file_put_contents($path, json_encode([
            'format'           => $format,
            'version'          => $version,
            'exported_at'      => '2026-07-28T18:04:43+00:00',
            'includes_archive' => true,
            'owners'           => [['source_user_id' => 59, 'name' => 'Анна']],
            'items'            => $items,
        ], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function writeOwnersMap(string $email): string
    {
        $path = $this->tmpDir . '/owners.json';
        file_put_contents($path, json_encode(['59' => $email]));

        return $path;
    }

    private function photoFile(string $name): string
    {
        file_put_contents($this->tmpDir . '/photos/wi/ld/' . $name, 'fake');

        return '/images/wardrobe/wi/ld/' . $name;
    }

    /** @param list<array<string,mixed>> $items */
    private function runImport(array $items, string $email, array $options = [], int $version = 1): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:wardrobe:restore-backup');
        $tester  = new CommandTester($command);
        $tester->execute(array_merge([
            'backup'        => $this->writeBackup($items, $version),
            '--owners-map'  => $this->writeOwnersMap($email),
            '--photos-dir'  => $this->tmpDir . '/photos',
            '--source'      => 'test-source-' . $this->token,
        ], $options));

        return $tester;
    }

    private function newUserEmail(): string
    {
        return UserFactory::withEmail(self::getContainer(), 'harness-wardrobe-' . bin2hex(random_bytes(4)) . '@wearbase.ru')->getEmail();
    }

    public function testFullCardIsRestoredWithAllFields(): void
    {
        $email  = $this->newUserEmail();
        $tester = $this->runImport([$this->item()], $email);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $item = $this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Рубашка ' . $this->token]);
        self::assertNotNull($item);
        self::assertSame('Stradivarius', $item->getCustomBrandName());
        self::assertSame('голубой', $item->getColorName());
        self::assertSame('L', $item->getSize());
        self::assertSame('хлопок', $item->getMaterialText());
        self::assertSame('Индия', $item->getCountryOfOrigin());
        self::assertSame('2990.00', $item->getPrice());
        self::assertSame('2026-05-01', $item->getPurchasedAt()?->format('Y-m-d'));
        self::assertSame(WardrobeItem::COMPLETION_BASIC, $item->getCompletionStatus());
        self::assertSame('Рубашка', $item->getCategory(), 'legacy-имя категории сохраняется, когда code пуст');
        self::assertSame(WardrobeItem::SOURCE_IMPORT, $item->getSource());
        self::assertSame(1, $item->getItemNo());
    }

    public function testCategoryIsMatchedByCode(): void
    {
        $category = (new WardrobeCategory())->setCode('tank_top')->setName('Майка');
        $this->em->persist($category);
        $this->em->flush();

        $tester = $this->runImport([$this->item(['category' => ['code' => 'tank_top', 'name' => 'Майка', 'parent' => null]])], $this->newUserEmail());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $item = $this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Рубашка ' . $this->token]);
        self::assertSame('tank_top', $item?->getCategoryRef()?->getCode());
    }

    public function testUnknownCategoryCodeStopsImport(): void
    {
        $tester = $this->runImport([$this->item(['category' => ['code' => 'no_such_code', 'name' => 'X', 'parent' => null]])], $this->newUserEmail());

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('no_such_code', $tester->getDisplay());
    }

    public function testLegacyAndGalleryUrlCollapseIntoSingleCoverPhoto(): void
    {
        $url    = $this->photoFile('wildberries-' . $this->token . '.webp');
        $tester = $this->runImport([$this->item(['photos' => [
            ['url' => $url, 'type' => 'legacy', 'cover' => true],
            ['url' => $url, 'type' => 'cover', 'cover' => true],
        ]])], $this->newUserEmail());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $item = $this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Рубашка ' . $this->token]);
        self::assertCount(1, $item?->getActivePhotos() ?? [], 'один файл = одна запись галереи');
        self::assertSame('wildberries-' . $this->token . '.webp', $item?->getCoverPhoto()?->getFilePath());
        self::assertTrue($item?->getCoverPhoto()?->isCover());
    }

    public function testMissingPhotoFileStopsImport(): void
    {
        $tester = $this->runImport([$this->item(['photos' => [
            ['url' => '/images/wardrobe/wi/ld/wildberries-never-uploaded.webp', 'type' => 'cover', 'cover' => true],
        ]])], $this->newUserEmail());

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('не найден', $tester->getDisplay());
    }

    public function testPathTraversalInPhotoUrlIsRejected(): void
    {
        $tester = $this->runImport([$this->item(['photos' => [
            ['url' => '/images/wardrobe/../../../.env.local', 'type' => 'cover', 'cover' => true],
        ]])], $this->newUserEmail());

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('небезопасный', $tester->getDisplay());
    }

    public function testRepeatedImportCreatesNoDuplicates(): void
    {
        $email = $this->newUserEmail();
        $this->runImport([$this->item()], $email);
        $tester = $this->runImport([$this->item()], $email);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('уже перенесено: 1', $tester->getDisplay());
        self::assertCount(1, $this->em->getRepository(WardrobeItem::class)->findBy(['name' => 'Рубашка ' . $this->token]));
    }

    public function testItemNoConflictStopsImportUnlessRenumberRequested(): void
    {
        $email = $this->newUserEmail();
        $this->runImport([$this->item()], $email);

        // Тот же номер, но ДРУГАЯ вещь из другого источника → конфликт номера.
        $conflicting = $this->item(['source_item_id' => 777, 'name' => 'Другая вещь ' . $this->token]);

        $strict = $this->runImport([$conflicting], $email, ['--source' => 'other-source-' . $this->token]);
        self::assertNotSame(0, $strict->getStatusCode());
        self::assertStringContainsString('уже занят', $strict->getDisplay());

        $renumbered = $this->runImport([$conflicting], $email, ['--source' => 'other-source-' . $this->token, '--renumber-conflicts' => true]);
        self::assertSame(0, $renumbered->getStatusCode(), $renumbered->getDisplay());
        $item = $this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Другая вещь ' . $this->token]);
        self::assertSame(2, $item?->getItemNo());
    }

    public function testUnknownOwnerStopsImport(): void
    {
        $path = $this->writeBackup([$this->item()]);
        $map  = $this->tmpDir . '/owners.json';
        file_put_contents($map, json_encode(['59' => 'no-such-user@wearbase.ru']));

        $command = (new Application(self::$kernel))->find('app:wardrobe:restore-backup');
        $tester  = new CommandTester($command);
        $tester->execute(['backup' => $path, '--owners-map' => $map, '--photos-dir' => $this->tmpDir . '/photos', '--source' => 'test-source-' . $this->token]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('не найден', $tester->getDisplay());
    }

    public function testUnknownFormatVersionStopsImport(): void
    {
        $tester = $this->runImport([$this->item()], $this->newUserEmail(), [], 2);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('не поддерживается', $tester->getDisplay());
    }

    public function testBackupWithTransfersIsRejected(): void
    {
        $tester = $this->runImport([$this->item(['transfers' => [
            ['source_transfer_id' => 5, 'from' => null, 'to' => ['source_user_id' => 59], 'transferred_at' => '2026-01-01T00:00:00+00:00'],
        ]])], $this->newUserEmail());

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('передач', $tester->getDisplay());
    }

    public function testInvalidItemInBatchRollsBackEverything(): void
    {
        $email = $this->newUserEmail();
        $good  = $this->item(['source_item_id' => 1, 'item_no' => 10, 'name' => 'Хорошая вещь ' . $this->token]);
        $bad   = $this->item(['source_item_id' => 2, 'item_no' => 11, 'name' => 'Плохая вещь ' . $this->token, 'item_status' => 'no_such_status']);

        $tester = $this->runImport([$good, $bad], $email);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertNull($this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Хорошая вещь ' . $this->token]), 'валидная вещь тоже не должна создаться');
    }

    public function testDryRunWritesNothing(): void
    {
        $db     = self::getContainer()->get(Connection::class);
        $before = (int) $db->fetchOne('SELECT COUNT(*) FROM wardrobe_import_map');

        $tester = $this->runImport([$this->item()], $this->newUserEmail(), ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNull($this->em->getRepository(WardrobeItem::class)->findOneBy(['name' => 'Рубашка ' . $this->token]));
        self::assertSame($before, (int) $db->fetchOne('SELECT COUNT(*) FROM wardrobe_import_map'));
    }
}
