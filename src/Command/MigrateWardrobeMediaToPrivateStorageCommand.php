<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:wardrobe:migrate-private-media',
    description: 'Move legacy wardrobe photos out of the public web root',
)]
final class MigrateWardrobeMediaToPrivateStorageCommand extends Command
{
    /** @var array<string, string> */
    private array $directories;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        parent::__construct();

        $this->directories = [
            $projectDir.'/public_html/images/wardrobe' => $projectDir.'/var/uploads/wardrobe',
            $projectDir.'/public_html/images/wardrobe_drafts' => $projectDir.'/var/uploads/wardrobe_drafts',
        ];
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report files without moving them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $moved = 0;
        $deduplicated = 0;
        $errors = [];

        foreach ($this->directories as $sourceRoot => $targetRoot) {
            if (!is_dir($sourceRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $source = $file->getPathname();
                $relative = substr($source, strlen($sourceRoot) + 1);
                $target = $targetRoot.'/'.$relative;

                if (is_file($target)) {
                    if (hash_file('sha256', $source) !== hash_file('sha256', $target)) {
                        $errors[] = sprintf('Different file already exists: %s', $relative);
                        continue;
                    }

                    if (!$dryRun && !unlink($source)) {
                        $errors[] = sprintf('Cannot remove duplicate source: %s', $relative);
                        continue;
                    }
                    ++$deduplicated;
                    continue;
                }

                if ($dryRun) {
                    ++$moved;
                    continue;
                }

                $targetDirectory = dirname($target);
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
                    $errors[] = sprintf('Cannot create private directory for: %s', $relative);
                    continue;
                }

                if (!rename($source, $target)) {
                    $errors[] = sprintf('Cannot move file: %s', $relative);
                    continue;
                }
                ++$moved;
            }

            if (!$dryRun) {
                $this->removeEmptyDirectories($sourceRoot);
            }
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->success(sprintf(
            '%s: moved %d, removed identical public duplicates %d, errors %d.',
            $dryRun ? 'Dry run' : 'Migration',
            $moved,
            $deduplicated,
            count($errors),
        ));

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function removeEmptyDirectories(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}
