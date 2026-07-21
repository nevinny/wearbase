<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Service\Seo\AioQueryClassifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Автопилот position-gap листа (docs/yandex_ai_visibility_monitoring.md): раньше
 * это был ручной прогон двух SQL-запросов по пятницам. Читает yandex_query_stats
 * (последний date_to) + gsc_query_stats (сумма по окну), берёт «показы есть,
 * позиция>10» (2-я страница со спросом) и раскладывает по группам интента —
 * ТОТ ЖЕ AioQueryClassifier, что app:seo:aio-queries и app:seo:aio-remediate
 * (никакой второй классификации не изобретаем).
 *
 * Группы (порядок = приоритет матча):
 * - brand_entity        — classifier: «X чей бренд» → проверить app:seo:aio-remediate.
 * - replace_comparison  — classifier: comparison ИЛИ RU-маркеры замены (замен-, аналог-,
 *                          «X это Y») → листикл app:seo:replace-listicle.
 * - geo_category        — гео-паттерн (спб/петербург/москва) → нет посадочной.
 * - navigation          — не подошло выше, но запрос содержит title/slug опубликованного
 *                          бренда → аудит карточки (скилл brand-audit).
 * - other               — не классифицировано.
 *
 * Только чтение по brand/*_query_stats. Побочный эффект — ОДНА строка на
 * (source,intent_group) в seo_gap_snapshot (для трендов неделя-к-неделе),
 * пропускается с --stdout-only. --notify шлёт компактную сводку в Telegram
 * тем же AdminNotifier, что и остальные SEO-команды (app:seo:aio-remediate).
 *
 *   php bin/console app:seo:gap-report --stdout-only
 *   php bin/console app:seo:gap-report --notify --no-debug   # крон, пн 08:00
 */
#[AsCommand(
    name: 'app:seo:gap-report',
    description: 'SEO: автопилот position-gap листа (position>10, спрос есть) — группировка по интенту + снапшот тренда',
)]
class SeoGapReportCommand extends Command
{
    /** RU-маркеры «замена/сравнение», не покрытые узким regex classifier'а (comparison там якорен на vs / сравн- / разниц-). */
    private const REPLACE_EXTRA_PATTERN = '/замен\w*|аналог\w*|\bэто\s+\p{L}/iu';

    /** Стартовый гео-список из реальных данных мониторинга (docs/yandex_ai_visibility_monitoring.md) — расширять по мере появления новых городов в gap-листе. */
    private const GEO_PATTERN = '/спб|санкт[- ]?петербург|петербург|москв/iu';

    private const GROUP_META = [
        'brand_entity'       => ['label' => 'Бренд/сущность («чей бренд»)', 'action' => 'проверить app:seo:aio-remediate по карточке (FAQ «Что за бренд?»)'],
        'replace_comparison' => ['label' => 'Замена/сравнение', 'action' => 'листикл app:seo:replace-listicle'],
        'geo_category'       => ['label' => 'Гео-категория', 'action' => 'нет посадочной — нужна страница-агрегатор по городу'],
        'navigation'         => ['label' => 'Навигационный (бренд рядом в топе)', 'action' => 'аудит полноты карточки (скилл brand-audit)'],
        'other'              => ['label' => 'Прочее', 'action' => '—'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AioQueryClassifier $classifier,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'yandex|gsc|both', 'both')
            ->addOption('min-shows', null, InputOption::VALUE_REQUIRED, 'Мин. показов, чтобы считать спрос', '10')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Топ-N строк на источник (по показам)', '40')
            ->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Только вывод, без снапшота и без TG')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести полные данные в JSON вместо таблиц')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить компактную сводку в Telegram (AdminNotifier)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $source     = (string) $input->getOption('source');
        $minShows   = max(1, (int) $input->getOption('min-shows'));
        $limit      = max(1, (int) $input->getOption('limit'));
        $stdoutOnly = (bool) $input->getOption('stdout-only');
        $json       = (bool) $input->getOption('json');
        $notify     = (bool) $input->getOption('notify');

        $io->title('SEO · gap-лист (position>10, спрос есть) — автопилот');

        $rows = $this->fetchGapRows($source, $minShows, $limit);
        if ($rows === []) {
            $io->warning('Gap-запросов нет (пусто в yandex_query_stats/gsc_query_stats или все на позиции ≤10).');
            return Command::SUCCESS;
        }

        $groups = $this->buildGroups($rows);

        if ($json) {
            $output->writeln(json_encode(['as_of' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'groups' => $groups], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $io->section(sprintf('Всего gap-строк: %d', count($rows)));
            foreach ($groups as $name => $g) {
                $io->section(sprintf('%s — %d', $g['label'], count($g['rows'])));
                $io->table(
                    ['Запрос', 'Показы', 'Позиция', 'Источник'],
                    array_map(
                        static fn (array $r) => [mb_substr($r['query'], 0, 60), $r['shows'], $r['position'], $r['source']],
                        $g['rows'],
                    ),
                );
                $io->text('→ ' . $g['action']);
                $io->newLine();
            }
            $io->section('Компактная сводка (превью того, что уйдёт в TG с --notify)');
            $io->text(strip_tags($this->formatDigest($groups)));
        }

        if (!$stdoutOnly) {
            $this->persistSnapshot($this->snapshotRows($groups));

            if ($notify && $this->notifier->isEnabled()) {
                $this->notifier->send($this->formatDigest($groups));
            }
        }

        return Command::SUCCESS;
    }

    /** @return list<array{query:string,shows:int,position:float,source:string}> */
    private function fetchGapRows(string $source, int $minShows, int $limit): array
    {
        $rows = [];
        if ($source === 'yandex' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchYandexGaps($minShows, $limit));
        }
        if ($source === 'gsc' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchGscGaps($minShows, $limit));
        }

        return $rows;
    }

    /** @return list<array{query:string,shows:int,position:float,source:string}> */
    private function fetchYandexGaps(int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query_text AS query, shows, position
                 FROM yandex_query_stats
                 WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)
                   AND position > 10 AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
            );
        } catch (\Throwable) {
            return []; // таблица не создана / крон синка ещё не отработал
        }

        return array_map(
            static fn (array $r) => ['query' => (string) $r['query'], 'shows' => (int) $r['shows'], 'position' => round((float) $r['position'], 1), 'source' => 'yandex'],
            $data,
        );
    }

    /** @return list<array{query:string,shows:int,position:float,source:string}> */
    private function fetchGscGaps(int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query, SUM(impressions) shows, AVG(position) position
                 FROM gsc_query_stats
                 GROUP BY query
                 HAVING position > 10 AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $r) => ['query' => (string) $r['query'], 'shows' => (int) $r['shows'], 'position' => round((float) $r['position'], 1), 'source' => 'gsc'],
            $data,
        );
    }

    /**
     * Группировка gap-строк по интенту. Возвращает только группы с непустым списком,
     * порядок — приоритет из GROUP_META.
     *
     * @param list<array{query:string,shows:int,position:float,source:string}> $rows
     * @return array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string}>}>
     */
    private function buildGroups(array $rows): array
    {
        $brandNames = $this->fetchPublishedBrandNames();

        $groups = [];
        foreach (self::GROUP_META as $name => $meta) {
            $groups[$name] = ['label' => $meta['label'], 'action' => $meta['action'], 'rows' => []];
        }

        foreach ($rows as $row) {
            $groups[$this->classifyGroup($row['query'], $brandNames)]['rows'][] = $row;
        }

        foreach ($groups as &$g) {
            usort($g['rows'], static fn (array $a, array $b) => $b['shows'] <=> $a['shows']);
        }

        return array_filter($groups, static fn (array $g) => $g['rows'] !== []);
    }

    private function classifyGroup(string $query, array $brandNames): string
    {
        $intent = $this->classifier->classify($query)['name'];
        if ($intent === 'brand_entity') {
            return 'brand_entity';
        }
        if ($intent === 'comparison' || preg_match(self::REPLACE_EXTRA_PATTERN, $query) === 1) {
            return 'replace_comparison';
        }
        if (preg_match(self::GEO_PATTERN, $query) === 1) {
            return 'geo_category';
        }
        if ($this->matchesKnownBrand($query, $brandNames)) {
            return 'navigation';
        }

        return 'other';
    }

    private function matchesKnownBrand(string $query, array $brandNames): bool
    {
        $q = mb_strtolower($query);
        foreach ($brandNames as $name) {
            if (mb_strlen($name) >= 3 && mb_stripos($q, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> lowercase title+slug опубликованных брендов, для навигационного матча. */
    private function fetchPublishedBrandNames(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT title, slug FROM brand WHERE status = 'active' AND published_at IS NOT NULL",
        );

        $names = [];
        foreach ($rows as $r) {
            if (!empty($r['title'])) {
                $names[] = mb_strtolower((string) $r['title']);
            }
            if (!empty($r['slug'])) {
                $names[] = mb_strtolower((string) $r['slug']);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string}>}> $groups
     * @return list<array{source:string,group:string,count:int,top_query:string}>
     */
    private function snapshotRows(array $groups): array
    {
        $bySourceGroup = [];
        foreach ($groups as $name => $g) {
            foreach ($g['rows'] as $row) {
                $key = $row['source'] . '|' . $name;
                if (!isset($bySourceGroup[$key])) {
                    $bySourceGroup[$key] = ['source' => $row['source'], 'group' => $name, 'count' => 0, 'top_query' => $row['query'], 'top_shows' => $row['shows']];
                }
                $bySourceGroup[$key]['count']++;
                if ($row['shows'] > $bySourceGroup[$key]['top_shows']) {
                    $bySourceGroup[$key]['top_query'] = $row['query'];
                    $bySourceGroup[$key]['top_shows'] = $row['shows'];
                }
            }
        }

        return array_values($bySourceGroup);
    }

    /** @param list<array{source:string,group:string,count:int,top_query:string}> $rows */
    private function persistSnapshot(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        foreach ($rows as $r) {
            $this->db->executeStatement(
                'INSERT INTO seo_gap_snapshot (captured_on, source, intent_group, gap_count, top_query)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gap_count = VALUES(gap_count), top_query = VALUES(top_query)',
                [$today, $r['source'], $r['group'], $r['count'], mb_substr($r['top_query'], 0, 255)],
            );
        }
    }

    /**
     * Компактная HTML-сводка под Telegram (parse_mode=HTML, см. TelegramNotifier) — топ-3
     * запроса на группу, обрезка по символам, чтобы не разваливать сообщение.
     *
     * @param array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string}>}> $groups
     */
    private function formatDigest(array $groups, int $topPerGroup = 3, int $charCap = 1500): string
    {
        $lines = [sprintf('<b>🕳 SEO gap-лист · %s</b>', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'))];

        foreach ($groups as $g) {
            $lines[] = sprintf("\n<b>%s (%d):</b>", htmlspecialchars($g['label']), count($g['rows']));
            foreach (array_slice($g['rows'], 0, $topPerGroup) as $row) {
                $lines[] = sprintf('• %s — %d показ., поз.%s [%s]', htmlspecialchars($row['query']), $row['shows'], $row['position'], $row['source']);
            }
            $lines[] = '→ ' . htmlspecialchars($g['action']);
        }

        $msg = implode("\n", $lines);
        if (mb_strlen($msg) > $charCap) {
            $msg = mb_substr($msg, 0, $charCap - 1) . '…';
        }

        return $msg;
    }
}
