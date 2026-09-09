<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WardrobeItemPhoto;
use App\Repository\WardrobeItemPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * У уже сохранённых фото EXIF срезан санитайзером, потерянный поворот программно
 * не отличить — поэтому кандидатов задаёт оператор: явным списком id и/или окном
 * создания записей (известный диапазон пострадавших: id ~51–55). Сначала посмотреть
 * кандидатов: --dry-run. Реальный прогон требует визуально выверенного угла --angle
 * (по часовой), одинакового для всех выбранных файлов.
 */
#[AsCommand(
    name: 'app:images:reorient',
    description: 'List or rotate wardrobe photos that lost their EXIF orientation',
)]
final class ReorientImagesCommand extends Command
{
    private const UPLOAD_SUBDIR_CHARS = 2;

    public function __construct(
        private readonly WardrobeItemPhotoRepository $photos,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/var/uploads/wardrobe')] private readonly string $uploadDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ids', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'WardrobeItemPhoto ids, e.g. --ids=51,52,53')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only photos created at/after this moment')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Only photos created at/before this moment')
            ->addOption('angle', null, InputOption::VALUE_REQUIRED, 'Clockwise correction for a real run: 90, 180 or 270')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List candidates without touching anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ids = $this->parseIds($input->getOption('ids'));
        if ($ids === null) {
            $io->error('Не удалось разобрать --ids: ожидается список чисел через запятую.');

            return Command::INVALID;
        }

        try {
            $sinceOption = $input->getOption('since');
            $untilOption = $input->getOption('until');
            $since = $sinceOption !== null ? new \DateTimeImmutable((string) $sinceOption) : null;
            $until = $untilOption !== null ? new \DateTimeImmutable((string) $untilOption) : null;
        } catch (\Exception) {
            $io->error('Не удалось разобрать --since/--until.');

            return Command::INVALID;
        }

        if ($ids === [] && $since === null && $until === null) {
            $io->error('Укажите --ids и/или окно --since/--until: без этого кандидатов не выбрать (EXIF у сохранённых файлов уже нет).');

            return Command::INVALID;
        }

        /** @var list<WardrobeItemPhoto> $candidates */
        $candidates = $this->photos->findReorientCandidates($ids === [] ? null : $ids, $since, $until);
        if ($candidates === []) {
            $io->warning('Кандидатов не найдено.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($candidates as $photo) {
            $rows[] = [
                $photo->getId(),
                (string) $photo->getFilePath(),
                $photo->getCreatedAt()->format('Y-m-d H:i'),
                is_file($this->resolvePath((string) $photo->getFilePath())) ? 'ok' : 'missing',
            ];
        }

        if ($input->getOption('dry-run')) {
            $io->table(['Id', 'File', 'Created', 'On disk'], $rows);
            $io->success(sprintf('Dry run: %d candidate(s).', count($candidates)));

            return Command::SUCCESS;
        }

        $angle = $input->getOption('angle');
        if (!in_array($angle, ['90', '180', '270'], true)) {
            $io->error('Для реального прогона укажите --angle=90|180|270 (по часовой; сначала проверьте файлы через --dry-run).');

            return Command::INVALID;
        }

        $rotated = 0;
        $errors = [];
        foreach ($candidates as $photo) {
            $path = $this->resolvePath((string) $photo->getFilePath());
            if (!is_file($path)) {
                $errors[] = sprintf('#%d %s: файл отсутствует', $photo->getId(), $photo->getFilePath());
                continue;
            }
            $image = @imagecreatefromstring((string) file_get_contents($path));
            if ($image === false || !imagejpeg(imagerotate($image, -(int) $angle, 0), $path, 90)) {
                $errors[] = sprintf('#%d %s: не удалось перезаписать', $photo->getId(), $photo->getFilePath());
                continue;
            }
            imagedestroy($image);
            $photo->setFileSize((int) filesize($path))
                ->setUpdatedAt(new \DateTimeImmutable());
            ++$rotated;
        }

        if ($rotated > 0) {
            $this->entityManager->flush();
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->success(sprintf('%s: rotated %d of %d candidate(s), errors %d.', $errors === [] ? 'Done' : 'Partial', $rotated, count($candidates), count($errors)));

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<string> $rawIds
     *  @return list<int>|null null = разбор не удался */
    private function parseIds(array $rawIds): ?array
    {
        if ($rawIds === []) {
            return [];
        }
        $ids = [];
        foreach ($rawIds as $chunk) {
            foreach (explode(',', $chunk) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (!ctype_digit($part)) {
                    return null;
                }
                $ids[] = (int) $part;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /** Тот же layout, что у Vich SubdirDirectoryNamer(chars_per_dir: 2, dirs: 2). */
    private function resolvePath(string $filePath): string
    {
        $prefix = substr($filePath, 0, self::UPLOAD_SUBDIR_CHARS).'/'.substr($filePath, self::UPLOAD_SUBDIR_CHARS, self::UPLOAD_SUBDIR_CHARS);

        return $this->uploadDir.'/'.$prefix.'/'.$filePath;
    }
}
