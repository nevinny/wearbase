<?php

namespace App\Command;

use App\Service\Knowledge\KnowledgeIngestor;
use App\Service\Knowledge\TgChannelScraper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Регулярный инкремент публичного TG-канала (уже описанного в role-карте
 * KnowledgeIngestor::ROLE_MAP) в базу знаний советника: скрап `t.me/s/<channel>`
 * → новые посты в ~/yt-kb/txt/<channel>/<id>.txt → чанкинг+эмбеддинг → Qdrant
 * topic_chunks. UUID точек, role-карта и чанкер — общие с app:kb:ingest-channels
 * (сервис KnowledgeIngestor), поэтому повторный прогон идемпотентен, а не плодит
 * дрейф эмбеддингов.
 *
 * Уже существующий .txt для id — пропускается целиком (ни перезаписи, ни
 * ре-эмбеддинга), это и делает инкремент дешёвым.
 *
 * ⚠️ Известное ограничение TgChannelScraper: `/s/<channel>` отдаёт только
 * последнюю страницу превью (~20 постов). При ежедневной каденции крона окна
 * хватает; при длительном пропуске (простой сервера/крона) возможен gap в
 * истории — тогда backfill делать вручную прежней одноразовой цепочкой
 * `discover?before=<id>` (как при первичной заливке DrMax, docs/drmax_seo_2026_digest.md).
 *
 *   php bin/console app:kb:sync-tg --channel=drmaxseo --dry-run
 *   php bin/console app:kb:sync-tg --channel=drmaxseo --limit=5
 *   php -d memory_limit=512M bin/console app:kb:sync-tg --channel=drmaxseo --no-debug
 */
#[AsCommand(
    name: 'app:kb:sync-tg',
    description: 'KB: инкрементальный синк публичного TG-канала → topic_chunks',
)]
class SyncTgChannelCommand extends Command
{
    public function __construct(
        private readonly TgChannelScraper $scraper,
        private readonly KnowledgeIngestor $ingestor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'TG-канал (' . implode(', ', $this->ingestor->channels()) . ')')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Только показать новые посты, без записи/эмбеддинга')
            ->addOption('limit',   null, InputOption::VALUE_REQUIRED, 'Первые N постов из выдачи (для теста)')
            ->addOption('path',    null, InputOption::VALUE_REQUIRED, 'Корень транскриптов (default $HOME/yt-kb/txt)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $channel = $input->getOption('channel');
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        if ($channel === null || $this->ingestor->roleFor($channel) === null) {
            $io->error(sprintf(
                'Нужен известный --channel. Доступны: %s',
                implode(', ', $this->ingestor->channels()),
            ));
            return Command::FAILURE;
        }

        $base = rtrim((string) ($input->getOption('path') ?: (getenv('HOME') . '/yt-kb/txt')), '/');
        $dir  = "{$base}/{$channel}";
        if (!is_dir($dir) && !$dryRun) {
            mkdir($dir, 0775, true);
        }

        $io->title("KB · синк TG-канала @{$channel} в topic_chunks");

        try {
            $posts = $this->scraper->fetchPosts($channel);
        } catch (\Throwable $e) {
            $io->error("Не удалось получить t.me/s/{$channel}: " . $e->getMessage());
            return Command::FAILURE;
        }

        if ($limit !== null) {
            $posts = array_slice($posts, 0, $limit);
        }

        $fetched   = count($posts);
        $newPosts  = [];
        foreach ($posts as $post) {
            if (!is_file("{$dir}/{$post['id']}.txt")) {
                $newPosts[] = $post;
            }
        }

        $io->text(sprintf('Постов получено: %d, новых: %d', $fetched, count($newPosts)));

        if ($dryRun) {
            if ($newPosts !== []) {
                $io->table(['ID', 'Дата', 'Превью'], array_map(
                    fn (array $p) => [$p['id'], $p['date']->format('Y-m-d'), mb_substr($p['text'], 0, 60)],
                    $newPosts,
                ));
            }
            $io->note('dry-run — без записи файлов и обращения к эмбеддеру/Qdrant');
            return Command::SUCCESS;
        }

        try {
            $this->ingestor->ensureCollection();
        } catch (\Throwable $e) {
            $io->warning('Qdrant недоступен — файлы будут записаны, эмбеддинг пропущен: ' . $e->getMessage());
        }

        $filesWritten = 0;
        $chunks = 0;
        $points = 0;
        $skipped = 0;

        foreach ($newPosts as $post) {
            $path = "{$dir}/{$post['id']}.txt";
            $content = sprintf("%s · %s\n\n%s\n", $post['title'], $post['date']->format('Y-m-d'), $post['text']);
            file_put_contents($path, $content);
            $filesWritten++;

            try {
                $result = $this->ingestor->ingestDocument($channel, (string) $post['id'], $post['text']);
                $chunks  += $result['chunks'];
                $points  += $result['points'];
                $skipped += $result['skipped'];
            } catch (\Throwable $e) {
                $io->warning(sprintf('  ✗ %d: embed/upsert не выполнен — %s', $post['id'], $e->getMessage()));
            }
        }

        $io->newLine();
        $io->table(['Итог', 'Кол-во'], [
            ['Постов получено',   $fetched],
            ['Новых',             count($newPosts)],
            ['Файлов записано',   $filesWritten],
            ['Чанков',            $chunks],
            ['Точек в Qdrant',    $points],
            ['Пропущено чанков',  $skipped],
        ]);

        return Command::SUCCESS;
    }
}
