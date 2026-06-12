<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перекладывает существующие файлы изображений из плоской структуры
 * в двухуровневые поддиректории (ab/cd/filename.jpg).
 *
 * Vich SubdirDirectoryNamer строит путь из первых N символов имени файла.
 * В БД хранится только basename — обновлять её не нужно.
 *
 * Использование:
 *   php bin/console app:migrate-images-to-subdirs
 *   php bin/console app:migrate-images-to-subdirs --dry-run   # только показать, не перемещать
 */
#[AsCommand(
    name: 'app:migrate-images-to-subdirs',
    description: 'Move existing flat image files into ab/cd/ subdirectory structure for SubdirDirectoryNamer',
)]
class MigrateImagesToSubdirsCommand extends Command
{
    // chars_per_dir=2, dirs=2 — должно совпадать с vich_uploader.yaml
    private const CHARS_PER_DIR = 2;
    private const DIRS = 2;

    /**
     * Маппинг: [директория на диске => список (entity, поле)]
     */
    private array $mappings;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();

        $root = $this->projectDir . '/public_html';

        $this->mappings = [
            $root . '/images/logos'  => [
                ['entity' => Brand::class, 'field' => 'logo'],
            ],
            $root . '/images/brands' => [
                ['entity' => BrandImage::class, 'field' => 'preview'],
                ['entity' => BrandImage::class, 'field' => 'image'],
            ],
        ];
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be moved without actually moving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('DRY-RUN mode — файлы не перемещаются');
        }

        $moved  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($this->mappings as $baseDir => $entityFields) {
            foreach ($entityFields as ['entity' => $entityClass, 'field' => $field]) {
                $getter = 'get' . ucfirst($field);
                $records = $this->em->getRepository($entityClass)->findAll();

                foreach ($records as $record) {
                    $filename = $record->$getter();

                    if (empty($filename)) {
                        continue;
                    }

                    $srcPath = $baseDir . '/' . $filename;

                    // Уже в подпапке — пропускаем
                    if (!file_exists($srcPath)) {
                        $skipped++;
                        continue;
                    }

                    $subdir  = $this->buildSubdir($filename);
                    $destDir = $baseDir . '/' . $subdir;
                    $destPath = $destDir . '/' . $filename;

                    if ($dryRun) {
                        $io->writeln(sprintf(
                            '  <comment>MOVE</comment> %s → %s/%s',
                            $filename, $subdir, $filename
                        ));
                        $moved++;
                        continue;
                    }

                    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
                        $io->error("Не удалось создать директорию: $destDir");
                        $errors++;
                        continue;
                    }

                    if (rename($srcPath, $destPath)) {
                        $moved++;
                        $io->writeln(sprintf(
                            '  <info>OK</info> %s → %s/%s',
                            $filename, $subdir, $filename
                        ));
                    } else {
                        $io->error("Не удалось переместить: $srcPath → $destPath");
                        $errors++;
                    }
                }
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s: перемещено %d, уже в подпапке %d, ошибок %d',
            $dryRun ? 'DRY-RUN итог' : 'Итог',
            $moved,
            $skipped,
            $errors
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * ab/cd из первых (CHARS_PER_DIR * DIRS) символов имени файла.
     * Совпадает с логикой SubdirDirectoryNamer.
     */
    private function buildSubdir(string $filename): string
    {
        $parts = [];
        $start = 0;
        for ($i = 0; $i < self::DIRS; $i++, $start += self::CHARS_PER_DIR) {
            $part = substr($filename, $start, self::CHARS_PER_DIR);
            // Если имя файла короче — используем подпапку '00' как fallback
            $parts[] = strlen($part) === self::CHARS_PER_DIR ? $part : str_pad($part, self::CHARS_PER_DIR, '0');
        }
        return implode('/', $parts);
    }
}
