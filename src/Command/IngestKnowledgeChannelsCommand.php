<?php

namespace App\Command;

use App\Service\Knowledge\KnowledgeIngestor;
use App\Service\TextChunker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ингест базы знаний из YouTube-транскриптов в векторный RAG.
 *
 * Читает очищенные .txt (~/yt-kb/txt/<channel>/<video_id>.txt), режет тем же
 * TextChunker'ом, что и бренды, считает эмбеддинги ЭМБЕДДЕРОМ (qwen3-embedding:0.6b,
 * НЕ gemma-генерация — можно параллелить с идущим брендовым конвейером) и заливает
 * в отдельную Qdrant-коллекцию `topic_chunks` (та же размерность 1024, что brand_chunks).
 * Чанкинг/эмбеддинг/upsert и role-карта — в общем сервисе KnowledgeIngestor (общий
 * с app:kb:sync-tg, чтобы UUID-namespace не разъезжались).
 *
 * ID точки детерминирован (UUIDv5 от channel:video_id:chunk_index) → повторный
 * прогон делает upsert, а не дубли: батч на тысячи чанков резюмируем после сбоя.
 *
 *   php bin/console app:kb:ingest-channels --dry-run
 *   php bin/console app:kb:ingest-channels --channel=mtokovinin --limit=2 --no-debug
 *   php -d memory_limit=512M bin/console app:kb:ingest-channels --no-debug
 */
#[AsCommand(
    name: 'app:kb:ingest-channels',
    description: 'KB: транскрипты YouTube → чанки → эмбеддинги → Qdrant topic_chunks',
)]
class IngestKnowledgeChannelsCommand extends Command
{
    private int $files   = 0;
    private int $chunks  = 0;
    private int $points  = 0;
    private int $skipped = 0;
    private int $failedFiles = 0;

    public function __construct(
        private readonly KnowledgeIngestor $ingestor,
        private readonly TextChunker $chunker,   // только для превью счётчиков в --dry-run
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel',  null, InputOption::VALUE_REQUIRED, 'Только один канал (' . implode(', ', $this->ingestor->channels()) . ')')
            ->addOption('limit',    null, InputOption::VALUE_REQUIRED, 'Первые N файлов на канал (для теста)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Только чанкинг+счётчики, без embed/upsert')
            ->addOption('recreate', null, InputOption::VALUE_NONE,     'Пересоздать коллекцию topic_chunks')
            ->addOption('path',     null, InputOption::VALUE_REQUIRED, 'Корень транскриптов (default $HOME/yt-kb/txt)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $only    = $input->getOption('channel');

        $base = rtrim((string) ($input->getOption('path') ?: (getenv('HOME') . '/yt-kb/txt')), '/');
        if (!is_dir($base)) {
            $io->error("Корень транскриптов не найден: {$base}");
            return Command::FAILURE;
        }

        $channels = $this->ingestor->channels();
        if ($only !== null) {
            if ($this->ingestor->roleFor($only) === null) {
                $io->error("Неизвестный канал «{$only}». Доступны: " . implode(', ', $channels));
                return Command::FAILURE;
            }
            $channels = [$only];
        }

        $io->title('KB · ингест YouTube-транскриптов в topic_chunks');
        $io->text("Корень: {$base}");
        if ($dryRun) {
            $io->note('dry-run — без обращения к эмбеддеру/Qdrant');
        }

        if (!$dryRun) {
            try {
                if ($input->getOption('recreate')) {
                    $io->warning('--recreate: удаляю коллекцию topic_chunks');
                    $this->ingestor->dropCollection();
                }
                $this->ingestor->ensureCollection();
            } catch (\Throwable $e) {
                $io->error('Qdrant недоступен/несовместим: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $perChannel = [];

        foreach ($channels as $channel) {
            $dir = "{$base}/{$channel}";
            if (!is_dir($dir)) {
                $io->warning("Пропускаю: нет каталога {$dir}");
                continue;
            }
            $role  = $this->ingestor->roleFor($channel);
            $paths = glob("{$dir}/*.txt") ?: [];
            sort($paths);
            if ($limit !== null) {
                $paths = array_slice($paths, 0, $limit);
            }

            $io->section(sprintf('%s (role=%s) — файлов: %d', $channel, $role, count($paths)));
            $chFiles = 0;
            $chChunks = 0;

            foreach ($paths as $path) {
                $videoId = pathinfo($path, PATHINFO_FILENAME);
                try {
                    $text = (string) file_get_contents($path);

                    if ($dryRun) {
                        // dry-run: только чанкинг+счётчики, без embed/upsert
                        $pieces = $this->chunker->chunk($text);
                        if ($pieces === []) {
                            continue;
                        }
                        $chFiles++;
                        $chChunks += count($pieces);
                        $this->files++;
                        $this->chunks += count($pieces);
                        continue;
                    }

                    $result = $this->ingestor->ingestDocument($channel, $videoId, $text);
                    if ($result['chunks'] === 0) {
                        continue;
                    }

                    $chFiles++;
                    $chChunks += $result['chunks'];
                    $this->files++;
                    $this->chunks += $result['chunks'];
                    $this->points += $result['points'];
                    $this->skipped += $result['skipped'];

                    $io->text(sprintf('  → %s: %d чанк(ов)', $videoId, $result['chunks']));
                } catch (\Throwable $e) {
                    $this->failedFiles++;
                    $io->warning(sprintf('  ✗ %s: %s', $videoId, $e->getMessage()));
                }
            }

            $perChannel[] = [$channel, $role, $chFiles, $chChunks];
        }

        $io->newLine();
        $io->table(['Канал', 'Роль', 'Файлов', 'Чанков'], $perChannel);
        $io->table(['Итог', 'Кол-во'], [
            ['Файлов обработано',   $this->files],
            ['Чанков',              $this->chunks],
            ['Точек в Qdrant',      $dryRun ? '(dry-run)' : $this->points],
            ['Пропущено чанков',    $this->skipped],
            ['Файлов с ошибкой',    $this->failedFiles],
        ]);

        return $this->failedFiles > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
