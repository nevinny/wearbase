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
 * Регулярный инкремент публичного TG-канала (уже описанного в реестре
 * config/knowledge/channels.yaml) в базу знаний советника: скрап `t.me/s/<channel>`
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
 *   php -d memory_limit=512M bin/console app:kb:sync-tg --no-debug   # без --channel: все каналы реестра
 *   php -d memory_limit=512M bin/console app:kb:sync-tg --channel=drmaxseo --backfill --no-debug   # вглубь истории
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
            ->addOption('channel',   null, InputOption::VALUE_REQUIRED, 'TG-канал (' . implode(', ', $this->ingestor->channels()) . '); без опции — все каналы реестра')
            ->addOption('dry-run',   null, InputOption::VALUE_NONE,     'Только показать новые посты, без записи/эмбеддинга')
            ->addOption('limit',     null, InputOption::VALUE_REQUIRED, 'Первые N постов из выдачи (для теста)')
            ->addOption('path',      null, InputOption::VALUE_REQUIRED, 'Корень транскриптов (default $HOME/yt-kb/txt)')
            ->addOption('backfill',  null, InputOption::VALUE_NONE,     'Листать `?before=` вглубь истории, а не только последнюю страницу')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Лимит страниц при --backfill (default 50)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $channel = $input->getOption('channel');

        if ($channel !== null && $this->ingestor->roleFor($channel) === null) {
            $io->error(sprintf(
                'Неизвестный --channel. Доступны: %s',
                implode(', ', $this->ingestor->channels()),
            ));
            return Command::FAILURE;
        }

        $channels = $channel !== null ? [$channel] : $this->ingestor->channels();
        $failed   = 0;

        foreach ($channels as $ch) {
            if (!$this->syncChannel($io, $input, $ch)) {
                $failed++;
            }
        }

        if ($channel === null) {
            $io->newLine();
            $io->text(sprintf('Каналов обработано: %d, с ошибкой: %d', count($channels), $failed));
        }

        // Разовый прогон конкретного канала — падать, если он не удался.
        // Батч по всем каналам — падать только если ВСЕ каналы упали (сеть/Qdrant лежит целиком),
        // единичный сбой одного канала (напр. TG отдал 404) не должен рушить остальные.
        if ($failed > 0 && $failed === count($channels)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function syncChannel(SymfonyStyle $io, InputInterface $input, string $channel): bool
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        $base = rtrim((string) ($input->getOption('path') ?: (getenv('HOME') . '/yt-kb/txt')), '/');
        $dir  = "{$base}/{$channel}";
        if (!is_dir($dir) && !$dryRun) {
            mkdir($dir, 0775, true);
        }

        $io->title("KB · синк TG-канала @{$channel} в topic_chunks");

        $backfill = (bool) $input->getOption('backfill');
        $maxPages = $input->getOption('max-pages') !== null ? max(1, (int) $input->getOption('max-pages')) : 50;

        try {
            $posts = $backfill
                ? $this->fetchAllPages($io, $channel, $dir, $maxPages)
                : $this->scraper->fetchPosts($channel);
        } catch (\Throwable $e) {
            $io->error("Не удалось получить t.me/s/{$channel}: " . $e->getMessage());
            return false;
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
            return true;
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

        return true;
    }

    /**
     * Листает `?before=` вглубь истории, пока страница не окажется пустой
     * (дошли до самого начала канала) или самый старый пост страницы уже
     * лежит на диске (дальше — уже синканное в прошлых прогонах). Дедуп по
     * id на случай, если соседние страницы пересекаются на границе.
     *
     * @return list<array{id:int,text:string,date:\DateTimeImmutable,title:string}>
     */
    private function fetchAllPages(SymfonyStyle $io, string $channel, string $dir, int $maxPages): array
    {
        $all    = [];
        $seen   = [];
        $before = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $posts = $this->scraper->fetchPosts($channel, $before);
            if ($posts === []) {
                $io->text("  страница {$page}: пусто — дошли до начала канала");
                break;
            }

            $ids = array_map(static fn (array $p) => $p['id'], $posts);
            $oldest = min($ids);

            foreach ($posts as $post) {
                if (!isset($seen[$post['id']])) {
                    $seen[$post['id']] = true;
                    $all[] = $post;
                }
            }

            $io->text(sprintf('  страница %d: %d постов, старейший id=%d', $page, count($posts), $oldest));

            // Страница 1 всегда пересекается с тем, что уже взял обычный (не-backfill)
            // синк — проверять «уже на диске» тут рано, иначе backfill ни разу не
            // доберётся до страницы 2. Проверка имеет смысл только начиная со 2-й
            // страницы — это значит, что мы уже когда-то прошли backfill'ом дальше.
            if ($page > 1 && is_file("{$dir}/{$oldest}.txt")) {
                $io->text('  старейший пост страницы уже на диске — стоп');
                break;
            }

            $before = $oldest - 1;
        }

        return $all;
    }
}
