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
 * Две полосы позиций (--band), они не пересекаются и вместе покрывают 4-ю позицию и ниже:
 * - gap      — position > 10: вторая страница, посадочной либо нет, либо она слабая;
 * - striking — position 4–10 (3 < pos ≤ 10): страница УЖЕ в топ-10, но не в топ-3.
 *              Дожать её дешевле, чем родить новую: сверить с топ-3 по главному запросу
 *              и добавить недостающее (раздел, таблица, FAQ) — правка существующего URL.
 *              Для GSC URL-владелец резолвится из gsc_page_stats (там есть query),
 *              у Яндекса page-level запросов нет — там только сам запрос.
 *
 * Только чтение по brand/*_query_stats/gsc_page_stats. Побочный эффект — ОДНА строка на
 * (source,band,intent_group) в seo_gap_snapshot (для трендов неделя-к-неделе),
 * пропускается с --stdout-only. --notify шлёт компактную сводку в Telegram
 * тем же AdminNotifier, что и остальные SEO-команды (app:seo:aio-remediate).
 *
 *   php bin/console app:seo:gap-report --stdout-only
 *   php bin/console app:seo:gap-report --band=striking --stdout-only
 *   php bin/console app:seo:gap-report --notify --no-debug   # крон, пн 08:00
 */
#[AsCommand(
    name: 'app:seo:gap-report',
    description: 'SEO: автопилот position-листа (gap >10 и дожим 4–10, спрос есть) — группировка по интенту + снапшот тренда',
)]
class SeoGapReportCommand extends Command
{
    /** RU-маркеры «замена/сравнение», не покрытые узким regex classifier'а (comparison там якорен на vs / сравн- / разниц-). */
    private const REPLACE_EXTRA_PATTERN = '/замен\w*|аналог\w*|\bэто\s+\p{L}/iu';

    /** Стартовый гео-список из реальных данных мониторинга (docs/yandex_ai_visibility_monitoring.md) — расширять по мере появления новых городов в gap-листе. */
    private const GEO_PATTERN = '/спб|санкт[- ]?петербург|петербург|москв/iu';

    /**
     * Полосы позиций. `min`/`max` — границы (min исключительно, max включительно; null = без границы).
     * `action_prefix` дописывается перед интент-действием: в striking страница уже ранжируется,
     * поэтому дефолт — правка существующего URL, а не новая посадочная.
     */
    private const BAND_META = [
        'striking' => [
            'label'         => 'Дожим (топ-10 без топ-3, позиция 4–10)',
            'icon'          => '🎯',
            'min'           => 3.0,
            'max'           => 10.0,
            'action_prefix' => 'страница уже в топ-10 — сверить с топ-3 по главному запросу и добавить недостающее (раздел, таблица, FAQ); ',
        ],
        'gap' => [
            'label'         => 'Gap (2-я страница, позиция >10)',
            'icon'          => '🕳',
            'min'           => 10.0,
            'max'           => null,
            'action_prefix' => '',
        ],
    ];

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
            ->addOption('band', null, InputOption::VALUE_REQUIRED, 'striking (поз. 4–10) | gap (поз. >10) | both', 'both')
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
        $band       = (string) $input->getOption('band');
        $minShows   = max(1, (int) $input->getOption('min-shows'));
        $limit      = max(1, (int) $input->getOption('limit'));
        $stdoutOnly = (bool) $input->getOption('stdout-only');
        $json       = (bool) $input->getOption('json');
        $notify     = (bool) $input->getOption('notify');

        $bands = $this->resolveBands($band);
        if ($bands === []) {
            $io->error(sprintf('Неизвестная полоса --band=%s (ожидается striking|gap|both).', $band));
            return Command::INVALID;
        }

        $io->title('SEO · position-лист (дожим 4–10 + gap >10) — автопилот');

        $byBand = [];
        foreach ($bands as $bandName) {
            $rows = $this->fetchBandRows($bandName, $source, $minShows, $limit);
            if ($rows !== []) {
                $byBand[$bandName] = $this->buildGroups($rows);
            }
        }

        if ($byBand === []) {
            $io->warning('Строк нет (пусто в yandex_query_stats/gsc_query_stats или все позиции вне выбранных полос).');
            return Command::SUCCESS;
        }

        if ($json) {
            $output->writeln(json_encode(['as_of' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'bands' => $byBand], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($byBand as $bandName => $groups) {
                $meta  = self::BAND_META[$bandName];
                $total = array_sum(array_map(static fn (array $g) => count($g['rows']), $groups));
                $io->title(sprintf('%s %s — строк: %d', $meta['icon'], $meta['label'], $total));

                foreach ($groups as $g) {
                    $io->section(sprintf('%s — %d', $g['label'], count($g['rows'])));
                    $io->table(
                        ['Запрос', 'Показы', 'Позиция', 'Источник', 'URL-владелец'],
                        array_map(
                            static fn (array $r) => [mb_substr($r['query'], 0, 50), $r['shows'], $r['position'], $r['source'], $r['page'] !== null ? mb_substr($r['page'], 0, 45) : '—'],
                            $g['rows'],
                        ),
                    );
                    $io->text('→ ' . $meta['action_prefix'] . $g['action']);
                    $io->newLine();
                }
            }
            $io->section('Компактная сводка (превью того, что уйдёт в TG с --notify)');
            $io->text(strip_tags($this->formatDigest($byBand)));
        }

        if (!$stdoutOnly) {
            foreach ($byBand as $bandName => $groups) {
                $this->persistSnapshot($bandName, $this->snapshotRows($groups));
            }

            if ($notify && $this->notifier->isEnabled()) {
                $this->notifier->send($this->formatDigest($byBand));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Порядок важен: striking идёт первым и в консоли, и в TG — дожим существующей
     * страницы дешевле новой посадочной, поэтому он должен читаться раньше gap'а.
     *
     * @return list<string>
     */
    private function resolveBands(string $band): array
    {
        if ($band === 'both') {
            return array_keys(self::BAND_META);
        }

        return isset(self::BAND_META[$band]) ? [$band] : [];
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    private function fetchBandRows(string $band, string $source, int $minShows, int $limit): array
    {
        $rows = [];
        if ($source === 'yandex' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchYandexRows($band, $minShows, $limit));
        }
        if ($source === 'gsc' || $source === 'both') {
            $rows = array_merge($rows, $this->fetchGscRows($band, $minShows, $limit));
        }

        return $rows;
    }

    /**
     * SQL-условие полосы: min исключительно, max включительно — так striking (3<pos≤10)
     * и gap (pos>10) не пересекаются и один запрос не попадает в обе полосы.
     */
    private function bandCondition(string $band, string $column): string
    {
        $meta = self::BAND_META[$band];
        $cond = sprintf('%s > %.1f', $column, $meta['min']);
        if ($meta['max'] !== null) {
            $cond .= sprintf(' AND %s <= %.1f', $column, $meta['max']);
        }

        return $cond;
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    private function fetchYandexRows(string $band, int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query_text AS query, shows, position
                 FROM yandex_query_stats
                 WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)
                   AND ' . $this->bandCondition($band, 'position') . ' AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
            );
        } catch (\Throwable) {
            return []; // таблица не создана / крон синка ещё не отработал
        }

        // Вебмастер отдаёт запросы без URL — страницу-владельца по Яндексу мы не знаем.
        return array_map(
            static fn (array $r) => ['query' => (string) $r['query'], 'shows' => (int) $r['shows'], 'position' => round((float) $r['position'], 1), 'source' => 'yandex', 'page' => null],
            $data,
        );
    }

    /** @return list<array{query:string,shows:int,position:float,source:string,page:?string}> */
    private function fetchGscRows(string $band, int $minShows, int $limit): array
    {
        try {
            $data = $this->db->fetchAllAssociative(
                'SELECT query, SUM(impressions) shows, AVG(position) position
                 FROM gsc_query_stats
                 GROUP BY query
                 HAVING ' . $this->bandCondition($band, 'position') . ' AND shows >= ?
                 ORDER BY shows DESC LIMIT ' . $limit,
                [$minShows],
            );
        } catch (\Throwable) {
            return [];
        }

        $pages = $this->resolveGscPages(array_map(static fn (array $r) => (string) $r['query'], $data));

        return array_map(
            static fn (array $r) => [
                'query'    => (string) $r['query'],
                'shows'    => (int) $r['shows'],
                'position' => round((float) $r['position'], 1),
                'source'   => 'gsc',
                'page'     => $pages[(string) $r['query']] ?? null,
            ],
            $data,
        );
    }

    /**
     * Страница-владелец запроса — из gsc_query_page (срез query×page, пишет app:gsc:sync).
     * Берём URL с наибольшими показами: именно его и надо дожимать в полосе striking.
     * Пусто → '—' в отчёте: значит синк ещё не приносил этот срез (fail-open, не ошибка).
     *
     * @param list<string> $queries
     * @return array<string,string> запрос → URL
     */
    private function resolveGscPages(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT query, page_url, impressions AS shows
                 FROM gsc_query_page
                 WHERE query IN (?)
                 ORDER BY shows DESC',
                [$queries],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return [];
        }

        $pages = [];
        foreach ($rows as $r) {
            // ORDER BY shows DESC → первая строка на запрос и есть главная страница
            $pages[(string) $r['query']] ??= (string) $r['page_url'];
        }

        return $pages;
    }

    /**
     * Группировка строк полосы по интенту. Возвращает только группы с непустым списком,
     * порядок — приоритет из GROUP_META.
     *
     * @param list<array{query:string,shows:int,position:float,source:string,page:?string}> $rows
     * @return array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}>
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
     * @param array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}> $groups
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
    private function persistSnapshot(string $band, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        foreach ($rows as $r) {
            $this->db->executeStatement(
                'INSERT INTO seo_gap_snapshot (captured_on, source, band, intent_group, gap_count, top_query)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gap_count = VALUES(gap_count), top_query = VALUES(top_query)',
                [$today, $r['source'], $band, $r['group'], $r['count'], mb_substr($r['top_query'], 0, 255)],
            );
        }
    }

    /**
     * Компактная HTML-сводка под Telegram (parse_mode=HTML, см. TelegramNotifier) — топ-N
     * запросов на группу, обрезка по символам, чтобы не разваливать сообщение.
     * Полосы идут в порядке BAND_META: сначала дожим (дешевле), потом gap.
     *
     * @param array<string,array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}>> $byBand
     */
    private function formatDigest(array $byBand, int $topPerGroup = 3, int $charCap = 1800): string
    {
        $lines = [sprintf('<b>SEO position-лист · %s</b>', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'))];

        foreach ($byBand as $bandName => $groups) {
            $meta  = self::BAND_META[$bandName];
            $total = array_sum(array_map(static fn (array $g) => count($g['rows']), $groups));
            $lines[] = sprintf("\n<b>%s %s — %d</b>", $meta['icon'], htmlspecialchars($meta['label']), $total);

            foreach ($groups as $g) {
                $lines[] = sprintf('<b>%s (%d):</b>', htmlspecialchars($g['label']), count($g['rows']));
                foreach (array_slice($g['rows'], 0, $topPerGroup) as $row) {
                    $lines[] = sprintf(
                        '• %s — %d показ., поз.%s [%s]%s',
                        htmlspecialchars($row['query']),
                        $row['shows'],
                        $row['position'],
                        $row['source'],
                        $row['page'] !== null ? "\n  ↳ " . htmlspecialchars($row['page']) : '',
                    );
                }
                $lines[] = '→ ' . htmlspecialchars($meta['action_prefix'] . $g['action']);
            }
        }

        $msg = implode("\n", $lines);
        if (mb_strlen($msg) > $charCap) {
            $msg = mb_substr($msg, 0, $charCap - 1) . '…';
        }

        return $msg;
    }
}
