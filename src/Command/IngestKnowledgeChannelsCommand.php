<?php

namespace App\Command;

use App\Service\EmbeddingService;
use App\Service\TextChunker;
use App\Service\VectorStoreService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Ингест базы знаний из YouTube-транскриптов в векторный RAG.
 *
 * Читает очищенные .txt (~/yt-kb/txt/<channel>/<video_id>.txt), режет тем же
 * TextChunker'ом, что и бренды, считает эмбеддинги ЭМБЕДДЕРОМ (qwen3-embedding:0.6b,
 * НЕ gemma-генерация — можно параллелить с идущим брендовым конвейером) и заливает
 * в отдельную Qdrant-коллекцию `topic_chunks` (та же размерность 1024, что brand_chunks).
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
    private const EMBED_BATCH = 32;

    // Своё namespace для UUIDv5 точек topic_chunks (стабильный, не пересекается с brand_chunks).
    private const ID_NAMESPACE = 'b3d5c1a2-7e4f-5c9a-9b21-8f0e1d2c3a4b';

    /** Роль чанка в payload — определяется каналом-источником. */
    private const ROLE_MAP = [
        'grebenukm'          => 'idea',
        'dolgov_alexandr'    => 'idea',
        'mtokovinin'         => 'framing',
        'AlexanderSokolovskiy' => 'case',
        'FedotovM'           => 'tone',
        'drmaxseo'           => 'seo',
    ];

    private int $files   = 0;
    private int $chunks  = 0;
    private int $points  = 0;
    private int $skipped = 0;
    private int $failedFiles = 0;

    public function __construct(
        private readonly EmbeddingService   $embedder,
        private readonly VectorStoreService $vectors,   // инстанс, привязанный к topic_chunks (services.yaml)
        private readonly TextChunker        $chunker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel',  null, InputOption::VALUE_REQUIRED, 'Только один канал (' . implode(', ', array_keys(self::ROLE_MAP)) . ')')
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

        $channels = array_keys(self::ROLE_MAP);
        if ($only !== null) {
            if (!isset(self::ROLE_MAP[$only])) {
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
                    $this->vectors->dropCollection();
                }
                $this->vectors->ensureCollection();
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
            $role  = self::ROLE_MAP[$channel];
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
                    $pieces = $this->chunker->chunk($text);
                    if ($pieces === []) {
                        continue;
                    }

                    $chFiles++;
                    $chChunks += count($pieces);
                    $this->files++;
                    $this->chunks += count($pieces);

                    if ($dryRun) {
                        continue;
                    }

                    $this->embedFile($channel, $videoId, $role, $pieces);
                    $io->text(sprintf('  → %s: %d чанк(ов)', $videoId, count($pieces)));
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

    /**
     * Эмбеддит чанки одного файла по одному (устойчиво к NaN на мусорном чанке —
     * как в app:brand:embed) и грузит батчами в Qdrant.
     *
     * @param string[] $pieces
     */
    private function embedFile(string $channel, string $videoId, string $role, array $pieces): void
    {
        $batch = [];
        foreach ($pieces as $idx => $text) {
            try {
                $vec = $this->embedder->embed($text);
            } catch (\Throwable) {
                $this->skipped++;
                continue;
            }
            $batch[] = [
                'id'      => $this->pointId($channel, $videoId, $idx),
                'vector'  => $vec,
                'payload' => [
                    'channel'     => $channel,
                    'video_id'    => $videoId,
                    'role'        => $role,
                    'chunk_index' => $idx,
                    'text'        => $text,
                ],
            ];
            if (count($batch) >= self::EMBED_BATCH) {
                $this->vectors->upsertPoints($batch);
                $this->points += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->vectors->upsertPoints($batch);
            $this->points += count($batch);
        }
    }

    private function pointId(string $channel, string $videoId, int $chunkIndex): string
    {
        return (string) Uuid::v5(
            Uuid::fromString(self::ID_NAMESPACE),
            "{$channel}:{$videoId}:{$chunkIndex}",
        );
    }
}
