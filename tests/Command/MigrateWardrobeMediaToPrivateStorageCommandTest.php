<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MigrateWardrobeMediaToPrivateStorageCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateWardrobeMediaToPrivateStorageCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/wearbase-private-media-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->projectDir);
    }

    public function testMovesLegacyItemAndDraftPhotosPreservingSubdirectories(): void
    {
        $this->put('public_html/images/wardrobe/ab/cd/item.jpg', 'item');
        $this->put('public_html/images/wardrobe_drafts/ef/gh/draft.jpg', 'draft');

        $tester = new CommandTester(new MigrateWardrobeMediaToPrivateStorageCommand($this->projectDir));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileEquals($this->path('var/uploads/wardrobe/ab/cd/item.jpg'), $this->fixture('item'));
        self::assertFileEquals($this->path('var/uploads/wardrobe_drafts/ef/gh/draft.jpg'), $this->fixture('draft'));
        self::assertDirectoryDoesNotExist($this->path('public_html/images/wardrobe'));
        self::assertDirectoryDoesNotExist($this->path('public_html/images/wardrobe_drafts'));
    }

    public function testIdenticalPrivateFileMakesRetryIdempotent(): void
    {
        $this->put('public_html/images/wardrobe/ab/cd/item.jpg', 'same');
        $this->put('var/uploads/wardrobe/ab/cd/item.jpg', 'same');

        $tester = new CommandTester(new MigrateWardrobeMediaToPrivateStorageCommand($this->projectDir));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileDoesNotExist($this->path('public_html/images/wardrobe/ab/cd/item.jpg'));
        self::assertFileExists($this->path('var/uploads/wardrobe/ab/cd/item.jpg'));
    }

    public function testConflictingPrivateFileFailsWithoutDeletingEitherCopy(): void
    {
        $this->put('public_html/images/wardrobe/ab/cd/item.jpg', 'public');
        $this->put('var/uploads/wardrobe/ab/cd/item.jpg', 'private');

        $tester = new CommandTester(new MigrateWardrobeMediaToPrivateStorageCommand($this->projectDir));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame('public', file_get_contents($this->path('public_html/images/wardrobe/ab/cd/item.jpg')));
        self::assertSame('private', file_get_contents($this->path('var/uploads/wardrobe/ab/cd/item.jpg')));
    }

    public function testDryRunDoesNotChangeFiles(): void
    {
        $this->put('public_html/images/wardrobe/item.jpg', 'item');
        $tester = new CommandTester(new MigrateWardrobeMediaToPrivateStorageCommand($this->projectDir));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertFileExists($this->path('public_html/images/wardrobe/item.jpg'));
        self::assertFileDoesNotExist($this->path('var/uploads/wardrobe/item.jpg'));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
    }

    private function path(string $relative): string
    {
        return $this->projectDir.'/'.$relative;
    }

    private function fixture(string $contents): string
    {
        $path = $this->path('fixtures/'.bin2hex(random_bytes(4)));
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);

        return $path;
    }
}
