<?php

namespace App\Command;

use App\Service\Knowledge\KnowledgeChannelRegistry;
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
 * `/s/<channel>` отдаёт ~20 последних постов. Если после простоя крона на первой
 * странице нет стыка с уже сохранёнными постами, команда листает `?before=<id>`
 * назад до стыка (не больше BACKFILL_MAX_PAGES страниц). У канала без сохранённых
 * TG-постов берётся только первая страница — первичная заливка делается вручную.
 *
 * Без --channel обходит все каналы с `tg: true` в реестре (так работает ежедневный
 * крон); сбой одного канала не останавливает остальные, но итог — FAILURE.
 *
 *   php bin/console app:kb:sync-tg --dry-run                      # все TG-каналы
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
    private const BACKFILL_MAX_PAGES = 20;

    public function __construct(
        private readonly TgChannelScraper $scraper,
        private readonly KnowledgeIngestor $ingestor,
        private readonly KnowledgeChannelRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Один TG-канал; без опции — все с tg: true (' . implode(', ', $this->registry->tgChannels()) . ')')
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

        if ($channel !== null && $this->ingestor->roleFor($channel) === null) {
            $io->error(sprintf(
                'Неизвестный --channel «%s». Доступны: %s',
                $channel,
                implode(', ', $this->ingestor->channels()),
            ));
            return Command::FAILURE;
        }

        $channels = $channel !== null ? [$channel] : $this->registry->tgChannels();
        if ($channels === []) {
            $io->error('В config/knowledge/channels.yaml нет каналов с tg: true');
            return Command::FAILURE;
        }

        $base = rtrim((string) ($input->getOption('path') ?: (getenv('HOME') . '/yt-kb/txt')), '/');

        $failed = [];
        foreach ($channels as $ch) {
            if (!$this->syncChannel($io, $ch, $base, $dryRun, $limit)) {
                $failed[] = $ch;
            }
        }

        if ($failed !== []) {
            $io->error('Синк не удался: ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function syncChannel(SymfonyStyle $io, string $channel, string $base, bool $dryRun, ?int $limit): bool
    {
        $dir  = "{$base}/{$channel}";
        if (!is_dir($dir) && !$dryRun) {
            mkdir($dir, 0775, true);
        }

        $io->title("KB · синк TG-канала @{$channel} в topic_chunks");

        $maxKnown = $this->maxKnownPostId($dir);

        try {
            $posts = $this->scraper->fetchPosts($channel);
            $pages = 1;
            // Дозагрузка разрыва: первая страница целиком новее последнего сохранённого поста.
            while ($maxKnown > 0 && $posts !== [] && $pages < self::BACKFILL_MAX_PAGES) {
                $oldest = min(array_column($posts, 'id'));
                if ($oldest <= $maxKnown + 1) {
                    break;
                }
                $older = $this->scraper->fetchPosts($channel, $oldest);
                $pages++;
                if ($older === []) {
                    break;
                }
                $posts = array_merge($older, $posts);
            }
            if ($pages > 1) {
                $io->text(sprintf('Дозагрузка разрыва: страниц %d (последний сохранённый id %d)', $pages, $maxKnown));
                if ($pages >= self::BACKFILL_MAX_PAGES) {
                    $io->warning(sprintf('Достигнут лимит %d страниц — более старый разрыв не закрыт', self::BACKFILL_MAX_PAGES));
                }
            }
            $posts = array_values(array_column($posts, null, 'id'));
            usort($posts, fn (array $a, array $b) => $a['id'] <=> $b['id']);
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

    /** Максимальный id сохранённого TG-поста (файлы <число>.txt); 0 — таких нет. */
    private function maxKnownPostId(string $dir): int
    {
        $max = 0;
        foreach (glob("{$dir}/*.txt") ?: [] as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (ctype_digit($name)) {
                $max = max($max, (int) $name);
            }
        }

        return $max;
    }
}
