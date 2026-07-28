<?php

namespace App\Command;

use App\Service\Gsc\GscClient;
use App\Service\PageClassifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Синк Google Search Console (cron 1 раз в день, дизайн «аналитика+GSC»):
 *  1. Search Analytics одним батчем (до 25k строк, лаг GSC ~2-3 дня) → gsc_page_stats;
 *     brand_id резолвится по slug (срезая /{locale}/brands/) — суммирует 9 локалей.
 *  1a. Второй батч Search Analytics — dimensions=['query','date'] → gsc_query_stats
 *     (отдельный запрос, отдельная квота-строка; нужен для regex-свипа запросов под
 *     AI Overviews, см. app:seo:aio-queries). gsc_page_stats.query всегда NULL — это
 *     агрегат по странице, не по тексту запроса.
 *  1b. Индекс по Search Analytics: бренд с показами (ЛЮБАЯ локаль) = де-факто в индексе
 *     (показ невозможен без индексации). Помечаем indexed=1 БЕЗ инспекции — иначе инспекция
 *     одного ru-URL недосчитывает индексацию в разы (ранжируется en/tr/…, а ru ещё нет).
 *     Это и масштабируется: на десятках тысяч страниц индекс выводим из SA, не из квоты.
 *     То же самое — для не-брендовых страниц (blog/style/city/other), группировка по page_url
 *     (у них нет brand_id); gsc_index_status трекает ВСЕ страницы, не только бренды.
 *  2. URL Inspection (лимит Google 2000/день → cap 1500) — ДИАГНОСТИКА МОЛЧУНОВ: квоту
 *     тратим на свежеопубликованные + страницы БЕЗ показов (их и надо разбирать «почему не
 *     в индексе»); уже ранжирующиеся не инспектируем — они и так помечены п.1b.
 *  3. --report: алерты в лог + sitemaps-агрегат (submitted/errors без поштучной инспекции).
 *
 * FAIL-OPEN: без кредов — лог и exit 0. GSC никогда не тормозит дрип-публикацию.
 *
 * ПОЧЕМУ ТРИ ОТДЕЛЬНЫХ PULL'А, А НЕ ОДИН ['query','page','date'] (замер 2026-07-28):
 *  - квоту экономить не от чего: Search Analytics даёт 1200 QPM НА САЙТ, мы делаем 3 запроса
 *    в СУТКИ; усечения тоже нет — максимум 1653 строки против rowLimit=25000;
 *  - и главное: сведение одного среза query×page в page-агрегат теряет 50.7% показов
 *    (52 страницы из 126 исчезают целиком, ещё 34 недобирают) — GSC при группировке
 *    по query отбрасывает часть данных и анонимизирует редкие запросы;
 *  - обратное сведение query×page → query почти точное (0% потерь показов), но показы
 *    раздуваются ровно на каннибализированных запросах (property- vs page-агрегация),
 *    т.е. врало бы именно там, где gap-report ищет проблему. Смысла нет: запрос бесплатен.
 *
 *   0 6 * * * cd /path && php bin/console app:gsc:sync --report --no-debug >> var/log/gsc.log 2>&1
 */
#[AsCommand(
    name: 'app:gsc:sync',
    description: 'GSC: Search Analytics + покрытие индекса → gsc_page_stats / gsc_query_stats / gsc_index_status',
)]
class SyncGscCommand extends Command
{
    private const INSPECT_DAILY_CAP = 1500;  // лимит Google 2000/день, держим запас
    private const ANALYTICS_DAYS    = 7;     // тянем окно (upsert — повторы дёшевы)
    private const FRESH_DAYS        = 7;     // «свежие» для приоритета и алертов
    private const BLOG_WATCH_CAP    = 50;    // блог-статей инспектировать за прогон (closed-loop → Дзен)

    public function __construct(
        private readonly GscClient  $gsc,
        private readonly Connection $db,
        private readonly PageClassifier $pageClassifier,
        private readonly \App\Notification\AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('analytics-only', null, InputOption::VALUE_NONE, 'Только Search Analytics (без Inspection)')
            ->addOption('inspect-only',   null, InputOption::VALUE_NONE, 'Только покрытие индекса')
            ->addOption('inspect-cap',    null, InputOption::VALUE_REQUIRED, 'Потолок Inspection-запросов за прогон', (string) self::INSPECT_DAILY_CAP)
            ->addOption('report',         null, InputOption::VALUE_NONE, 'Отчёт/алерты по аномалиям')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GSC · синк Search Console');

        if (!$this->gsc->isConfigured()) {
            // fail-open: мониторинг не настроен — это не ошибка пайплайна
            $io->warning('GSC не настроен (GSC_CREDENTIALS_PATH / GSC_SITE_URL) — пропускаем.');
            return Command::SUCCESS;
        }

        try {
            if (!$input->getOption('inspect-only')) {
                $this->syncAnalytics($io);
                $this->syncQueryAnalytics($io);
                $this->syncQueryPageAnalytics($io);
                $this->markServedFromAnalytics($io);
            }
            if (!$input->getOption('analytics-only')) {
                $this->syncIndexCoverage($io, max(1, (int) $input->getOption('inspect-cap')));
            }
            if ($input->getOption('report')) {
                $this->report($io);
            }
        } catch (\Throwable $e) {
            $io->error('GSC sync: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** Окно синка — лаг GSC ~2-3 дня: [сегодня-2-N, сегодня-2]. @return array{0:\DateTime,1:\DateTime} */
    private function window(): array
    {
        return [new \DateTime(sprintf('-%d days', 2 + self::ANALYTICS_DAYS)), new \DateTime('-2 days')];
    }

    /**
     * Общий upsert-цикл трёх срезов. SQL остаётся у каждой таблицы (у них разные схемы и
     * разные уникальные ключи — это семантика, а не дубль); дублировался только цикл со
     * счётчиком и отбраковкой пустых строк. $params отдаёт null для мусорной строки.
     *
     * @param array<int,array<string,int|float|string>>                             $rows
     * @param callable(array<string,int|float|string>): (array<string,mixed>|null)  $params
     */
    private function upsert(string $sql, array $rows, callable $params): int
    {
        $upserted = 0;
        foreach ($rows as $row) {
            $bound = $params($row);
            if ($bound === null) {
                continue;
            }
            $this->db->executeStatement($sql, $bound);
            $upserted++;
        }

        return $upserted;
    }

    private function syncAnalytics(SymfonyStyle $io): void
    {
        [$from, $to] = $this->window();

        $rows = $this->gsc->searchAnalyticsByPage($from, $to);
        $io->text(sprintf('Search Analytics: %d строк (%s … %s)', count($rows), $from->format('Y-m-d'), $to->format('Y-m-d')));

        $upserted = $this->upsert(
            'INSERT INTO gsc_page_stats (page_url, brand_id, day, impressions, clicks, position, query)
             VALUES (:url, :brand_id, :day, :imp, :clicks, :pos, NULL)
             ON DUPLICATE KEY UPDATE impressions = :imp, clicks = :clicks, position = :pos, brand_id = :brand_id',
            $rows,
            fn (array $row): ?array => $row['page'] === '' || $row['date'] === '' ? null : [
                'url'      => mb_substr((string) $row['page'], 0, 512),
                'brand_id' => $this->resolveBrandId((string) $row['page']),
                'day'      => $row['date'],
                'imp'      => $row['impressions'],
                'clicks'   => $row['clicks'],
                'pos'      => $row['position'],
            ],
        );
        $io->text("Upsert в gsc_page_stats: {$upserted}");
    }

    /**
     * Второй pull Search Analytics — dimensions=['query','date'] → gsc_query_stats.
     * Отдельный от syncAnalytics() запрос (та же квота-логика, отдельная строка запроса),
     * т.к. GSC агрегирует show-per-page и show-per-query по-разному — их нельзя получить
     * одним вызовом без взрыва размера ответа (query×page). Разблокирует regex-свип
     * запросов под AI Overviews (docs/drmax_seo_2026_digest.md §5) — см. app:seo:aio-queries.
     */
    private function syncQueryAnalytics(SymfonyStyle $io): void
    {
        [$from, $to] = $this->window();

        $rows = $this->gsc->searchAnalyticsByQuery($from, $to);
        $io->text(sprintf('Search Analytics (query): %d строк (%s … %s)', count($rows), $from->format('Y-m-d'), $to->format('Y-m-d')));

        $upserted = $this->upsert(
            'INSERT INTO gsc_query_stats (query, day, impressions, clicks, ctr, position)
             VALUES (:query, :day, :imp, :clicks, :ctr, :pos)
             ON DUPLICATE KEY UPDATE impressions = :imp, clicks = :clicks, ctr = :ctr, position = :pos',
            $rows,
            fn (array $row): ?array => $row['query'] === '' || $row['date'] === '' ? null : [
                'query'  => mb_substr((string) $row['query'], 0, 255),
                'day'    => $row['date'],
                'imp'    => $row['impressions'],
                'clicks' => $row['clicks'],
                'ctr'    => $row['ctr'],
                'pos'    => $row['position'],
            ],
        );
        $io->text("Upsert в gsc_query_stats: {$upserted}");
    }

    /**
     * Третий pull — dimensions=['query','page'] за окно целиком → gsc_query_page.
     * Отвечает на вопрос «какой наш URL ранжируется по этому запросу»: без него полоса
     * дожима (app:seo:gap-report --band=striking) знает запрос, но не знает, что править.
     * Побочно: 2+ строки с показами на один запрос = кандидат на каннибализацию.
     */
    private function syncQueryPageAnalytics(SymfonyStyle $io): void
    {
        [$from, $to] = $this->window();

        $rows = $this->gsc->searchAnalyticsByQueryPage($from, $to);
        $io->text(sprintf('Search Analytics (query×page): %d строк (%s … %s)', count($rows), $from->format('Y-m-d'), $to->format('Y-m-d')));

        $today    = (new \DateTime())->format('Y-m-d');
        $upserted = $this->upsert(
            'INSERT INTO gsc_query_page (query, page_url, impressions, clicks, position, captured_on)
             VALUES (:query, :page, :imp, :clicks, :pos, :day)
             ON DUPLICATE KEY UPDATE impressions = :imp, clicks = :clicks, position = :pos, captured_on = :day',
            $rows,
            fn (array $row): ?array => $row['query'] === '' || $row['page'] === '' ? null : [
                'query'  => mb_substr((string) $row['query'], 0, 255),
                'page'   => mb_substr((string) $row['page'], 0, 512),
                'imp'    => $row['impressions'],
                'clicks' => $row['clicks'],
                'pos'    => $row['position'],
                'day'    => $today,
            ],
        );
        $io->text("Upsert в gsc_query_page: {$upserted}");
    }

    /**
     * Индекс по Search Analytics: бренд с показами (любая локаль) = де-факто в индексе.
     * first_indexed_at ← первый день с показами (а не NOW, чтобы не ломать time-to-index).
     * coverage_state инспекции (по ru-URL) НЕ затираем — он остаётся диагностикой ru-страницы.
     */
    private function markServedFromAnalytics(SymfonyStyle $io): void
    {
        $servedSub = "SELECT brand_id, MIN(day) first_day FROM gsc_page_stats
                      WHERE brand_id IS NOT NULL AND impressions > 0 GROUP BY brand_id";

        // 1) уже существующие строки: поднимаем indexed=1, фиксируем самый ранний first_indexed_at
        $this->db->executeStatement(
            "UPDATE gsc_index_status s
             JOIN ({$servedSub}) p ON p.brand_id = s.brand_id
             SET s.indexed = 1,
                 s.coverage_state = IF(s.coverage_state IS NULL OR s.coverage_state LIKE 'Served%',
                                       'Served (Search Analytics)', s.coverage_state),
                 s.first_indexed_at = LEAST(COALESCE(s.first_indexed_at, p.first_day), p.first_day)",
        );

        // 2) бренды с показами, которых ещё нет в gsc_index_status — заводим как Served
        $this->db->executeStatement(
            "INSERT INTO gsc_index_status (brand_id, page_url, coverage_state, indexed, last_checked_at, first_indexed_at)
             SELECT p.brand_id, CONCAT('https://wearbase.ru/ru/brands/', b.slug),
                    'Served (Search Analytics)', 1, NULL, p.first_day
             FROM ({$servedSub}) p
             JOIN brand b ON b.id = p.brand_id
             LEFT JOIN gsc_index_status s ON s.brand_id = p.brand_id
             WHERE s.brand_id IS NULL",
        );

        $served = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT brand_id) FROM gsc_page_stats WHERE brand_id IS NOT NULL AND impressions > 0",
        );
        $io->text("Индекс по Search Analytics: {$served} брендов с показами помечены проиндексированными (любая локаль)");

        // 3) не-брендовые страницы (блог/стили/города/прочее) с показами — тот же вывод индекса
        // из Search Analytics, но группировка по page_url (у них нет brand_id). Квоты не тратит —
        // данные уже собраны в п.1.
        $servedUrlSub = "SELECT page_url, MIN(day) first_day FROM gsc_page_stats
                          WHERE brand_id IS NULL AND impressions > 0 GROUP BY page_url";

        $this->db->executeStatement(
            "UPDATE gsc_index_status s
             JOIN ({$servedUrlSub}) p ON p.page_url = s.page_url
             SET s.indexed = 1,
                 s.coverage_state = IF(s.coverage_state IS NULL OR s.coverage_state LIKE 'Served%',
                                       'Served (Search Analytics)', s.coverage_state),
                 s.first_indexed_at = LEAST(COALESCE(s.first_indexed_at, p.first_day), p.first_day)",
        );

        $newUrls = $this->db->fetchAllAssociative(
            "SELECT p.page_url, p.first_day FROM ({$servedUrlSub}) p
             LEFT JOIN gsc_index_status s ON s.page_url = p.page_url
             WHERE s.page_url IS NULL",
        );
        foreach ($newUrls as $u) {
            $this->db->executeStatement(
                "INSERT INTO gsc_index_status (brand_id, page_url, page_type, coverage_state, indexed, last_checked_at, first_indexed_at)
                 VALUES (NULL, :url, :type, 'Served (Search Analytics)', 1, NULL, :first_day)",
                [
                    'url'       => $u['page_url'],
                    'type'      => $this->pageClassifier->classify($u['page_url']),
                    'first_day' => $u['first_day'],
                ],
            );
        }

        $servedPages = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT page_url) FROM gsc_page_stats WHERE brand_id IS NULL AND impressions > 0",
        );
        $io->text("Индекс по Search Analytics: {$servedPages} не-брендовых страниц с показами помечены проиндексированными");
    }

    private function syncIndexCoverage(SymfonyStyle $io, int $cap): void
    {
        $siteBase = 'https://wearbase.ru'; // канонический хост страниц брендов

        // Приоритет 1: свежеопубликованные дрипом (главный риск неиндексации)
        $fresh = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug FROM brand b
             LEFT JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.status = 'active' AND b.published_at >= :since
               AND (s.last_checked_at IS NULL OR s.last_checked_at < :today)
             ORDER BY b.published_at DESC LIMIT " . $cap,
            ['since' => (new \DateTime(sprintf('-%d days', self::FRESH_DAYS)))->format('Y-m-d H:i:s'),
             'today' => (new \DateTime('today'))->format('Y-m-d H:i:s')],
        );

        // Приоритет 2: очередь МОЛЧУНОВ — страницы без показов (их и надо диагностировать
        // «почему не в индексе»). Уже ранжирующиеся не трогаем: они помечены по Search Analytics,
        // инспекция их ru-URL лишь жгла бы квоту. Внутри — round-robin по давности проверки.
        $rest = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug FROM brand b
             LEFT JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.status = 'active'
               AND (s.last_checked_at IS NULL OR s.last_checked_at < :today)
               AND NOT EXISTS (SELECT 1 FROM gsc_page_stats g WHERE g.brand_id = b.id AND g.impressions > 0)
             ORDER BY s.last_checked_at IS NULL DESC, s.last_checked_at ASC
             LIMIT " . max(0, $cap - count($fresh)),
            ['today' => (new \DateTime('today'))->format('Y-m-d H:i:s')],
        );

        $targets = [];
        foreach (array_merge($fresh, $rest) as $row) {
            $targets[(int) $row['id']] = (string) $row['slug']; // дедуп по brand_id
        }
        $targets = array_slice($targets, 0, $cap, preserve_keys: true);

        // Бренды с показами: для них indexed=1 истинно (любая локаль), даже если ru-URL
        // инспекция вернёт «не в индексе» — иначе откатили бы корректную SA-метку.
        $servedSet = array_fill_keys(array_map('intval', $this->db->fetchFirstColumn(
            "SELECT DISTINCT brand_id FROM gsc_page_stats WHERE brand_id IS NOT NULL AND impressions > 0",
        )), true);

        $io->text(sprintf('Inspection: %d URL (cap %d, из них свежих %d)', count($targets), $cap, count($fresh)));
        $checked = $indexed = 0;

        foreach ($targets as $brandId => $slug) {
            $url = "{$siteBase}/ru/brands/{$slug}";
            try {
                $result = $this->gsc->inspectUrl($url);
            } catch (\Throwable $e) {
                // 429 = квота кончилась — дальше нет смысла
                if (str_contains($e->getMessage(), '429')) {
                    $io->warning('Квота URL Inspection исчерпана — остановка до завтра.');
                    break;
                }
                continue;
            }

            $isIndexed = $result['indexed'] || isset($servedSet[$brandId]);

            // first_indexed_at — момент ПЕРВОГО появления в индексе (time-to-index);
            // выставляется один раз и не сбрасывается при выпадении из индекса.
            $this->db->executeStatement(
                'INSERT INTO gsc_index_status (brand_id, page_url, coverage_state, indexed, last_checked_at, first_indexed_at)
                 VALUES (:brand_id, :url, :coverage, :indexed, NOW(), IF(:indexed = 1, NOW(), NULL))
                 ON DUPLICATE KEY UPDATE page_url = :url, coverage_state = :coverage, indexed = :indexed,
                     last_checked_at = NOW(),
                     first_indexed_at = COALESCE(first_indexed_at, IF(:indexed = 1, NOW(), NULL))',
                [
                    'brand_id' => $brandId,
                    'url'      => $url,
                    'coverage' => $result['coverageState'],
                    'indexed'  => $isIndexed ? 1 : 0,
                ],
            );
            $checked++;
            $indexed += $isIndexed ? 1 : 0;
            usleep(300_000); // вежливость к API
        }

        $io->text(sprintf('Проверено: %d, в индексе: %d', $checked, $indexed));
    }

    /** Алерты — read-only метрики; дрип-публикацию НЕ трогают (fail-open). */
    private function report(SymfonyStyle $io): void
    {
        $io->section('Отчёт');
        $alerts = [];
        $lines  = [];

        // Closed-loop «блог→Дзен»: свежепроиндексированные блог-статьи → строки в TG.
        $blogReady = $this->checkBlogIndex();
        foreach ($blogReady as $bl) {
            $io->text($bl);
        }

        // Общая индексация проверенных: indexed — volatile (снимается при выпадении показов
        // из 7-дневного окна SA), first_indexed_at — монотонная «когда-либо подтверждён».
        $total = $this->db->fetchAssociative(
            'SELECT COUNT(*) c, COALESCE(SUM(indexed),0) idx, SUM(first_indexed_at IS NOT NULL) ever FROM gsc_index_status',
        );
        if ($total && (int) $total['c'] > 0) {
            $lines[] = sprintf('Индексация: сейчас %d · когда-либо %d · из %d проверенных', $total['idx'], $total['ever'], $total['c']);
        }

        // Когорта «опубликовано ≥14 дней назад» — успели ли проиндексироваться за 14 дней
        $cohort = $this->db->fetchAssociative(
            "SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx FROM brand b
             JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at IS NOT NULL AND b.published_at <= :cutoff",
            ['cutoff' => (new \DateTime('-14 days'))->format('Y-m-d H:i:s')],
        );
        if ($cohort && (int) $cohort['checked'] >= 5) {
            $ratio = (int) $cohort['idx'] / (int) $cohort['checked'];
            $lines[] = sprintf('Когорта 14д+: %d/%d опубликованных в индексе (%.0f%%)', $cohort['idx'], $cohort['checked'], $ratio * 100);
            if ($ratio < 0.5) {
                $alerts[] = sprintf('⚠ Только %.0f%% страниц, опубликованных ≥14 дней назад, в индексе — дрип будет автоматически заторможен.', $ratio * 100);
            }
        }

        // Индексация свежеопубликованных
        $fresh = $this->db->fetchAssociative(
            "SELECT COUNT(*) total, COALESCE(SUM(s.indexed),0) idx FROM brand b
             LEFT JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at >= :since",
            ['since' => (new \DateTime(sprintf('-%d days', self::FRESH_DAYS)))->format('Y-m-d H:i:s')],
        );
        if ($fresh && (int) $fresh['total'] > 0) {
            $lines[] = sprintf('Свежие (%dд): %d, в индексе %d', self::FRESH_DAYS, $fresh['total'], $fresh['idx']);
        }

        // Time-to-index: скорость реакции Google на публикацию (published_at → first_indexed_at)
        $tti = $this->db->fetchAssociative(
            "SELECT COUNT(*) c, AVG(TIMESTAMPDIFF(HOUR, b.published_at, s.first_indexed_at)) avg_h
             FROM brand b JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at IS NOT NULL AND s.first_indexed_at IS NOT NULL AND s.first_indexed_at >= b.published_at",
        );
        if ($tti && (int) $tti['c'] >= 3) {
            $lines[] = sprintf('Time-to-index: в среднем %.1f дн. (%d стр.)', ((float) $tti['avg_h']) / 24, $tti['c']);
        }

        // Динамика показов день-к-дню (последние 2 полных дня в данных)
        $days = $this->db->fetchAllAssociative(
            'SELECT day, SUM(impressions) imp, SUM(clicks) clk FROM gsc_page_stats GROUP BY day ORDER BY day DESC LIMIT 2',
        );
        if (count($days) === 2) {
            [$d1, $d0] = $days;
            $lines[] = sprintf('Показы: %s=%d → %s=%d · клики: %d → %d', $d0['day'], $d0['imp'], $d1['day'], $d1['imp'], $d0['clk'], $d1['clk']);
            if ((int) $d0['imp'] > 100 && (int) $d1['imp'] < (int) $d0['imp'] / 2) {
                $alerts[] = '⚠ Показы упали >50% день-к-дню.';
            }
        }

        // Sitemaps-агрегат: покрытие/здоровье без поштучной инспекции (масштабируется на любой объём)
        try {
            $sitemaps = $this->gsc->listSitemaps();
            if ($sitemaps !== []) {
                $submitted = array_sum(array_column($sitemaps, 'submitted'));
                $errors    = array_sum(array_column($sitemaps, 'errors'));
                $warnings  = array_sum(array_column($sitemaps, 'warnings'));
                $served    = (int) $this->db->fetchOne(
                    "SELECT COUNT(DISTINCT brand_id) FROM gsc_page_stats WHERE brand_id IS NOT NULL AND impressions > 0",
                );
                $lines[] = sprintf('Sitemaps: %d шт · submitted %d URL · errors %d · warnings %d · с показами %d брендов',
                    count($sitemaps), $submitted, $errors, $warnings, $served);
                if ($errors > 0) {
                    $alerts[] = sprintf('⚠ В sitemap ошибок: %d — проверь Search Console.', $errors);
                }
            }
        } catch (\Throwable $e) {
            $lines[] = 'Sitemaps: запрос не удался (' . mb_substr($e->getMessage(), 0, 80) . ')';
        }

        foreach ($lines as $line) {
            $io->text($line);
        }
        foreach ($alerts as $alert) {
            $io->warning($alert);
        }

        // Телеграм: шлём при алертах ИЛИ при новых проиндексированных блог-статьях
        // (последние — позитивный сигнал «можно публиковать Дзен-вариант»).
        if (($alerts !== [] || $blogReady !== []) && $this->notifier->isEnabled()) {
            try {
                $this->notifier->send("<b>GSC wearbase.ru</b>\n" . implode("\n", array_merge($blogReady, $alerts, $lines)));
            } catch (\Throwable) {
                // нотификация не должна ронять синк
            }
        }
    }

    /**
     * Closed-loop «блог→Дзен»: инспектирует live блог-статьи из конвейера (source_file),
     * ещё не сигнализированные; попавшим в индекс ставит indexed_at/indexed_notified_at
     * и возвращает строки «готово к Дзену» для TG. Без TG-канала — не трогаем (иначе
     * потеряли бы сигнал). @return string[]
     */
    private function checkBlogIndex(): array
    {
        if (!$this->notifier->isEnabled()) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, slug, locale, title, source_file FROM article
             WHERE status = 'active' AND published_at IS NOT NULL AND published_at <= NOW()
               AND source_file IS NOT NULL AND indexed_notified_at IS NULL
             ORDER BY published_at ASC LIMIT " . self::BLOG_WATCH_CAP,
        );

        $lines = [];
        foreach ($rows as $r) {
            $url = sprintf('https://wearbase.ru/%s/blog/%s', $r['locale'], $r['slug']);
            try {
                $res = $this->gsc->inspectUrl($url);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '429')) {
                    break;  // квота — до завтра
                }
                continue;
            }
            if (!$res['indexed']) {
                usleep(300_000);
                continue;
            }
            $this->db->executeStatement(
                'UPDATE article SET indexed_at = COALESCE(indexed_at, NOW()), indexed_notified_at = NOW() WHERE id = :id',
                ['id' => $r['id']],
            );
            $lines[] = sprintf('✅ Проиндексирована: «%s» (%s) → публикуй Дзен-вариант: %s',
                mb_substr((string) $r['title'], 0, 80), $url, $this->dzenHint($r['source_file']));
            usleep(300_000);
        }

        return $lines;
    }

    /** Путь Дзен-варианта по имени блог-файла (swap -site→-dzen; персона листикла может отличаться → glob). */
    private function dzenHint(?string $sf): string
    {
        if ($sf === null || $sf === '') {
            return 'var/seo/dzen|guides/';
        }
        if (str_starts_with($sf, 'listicle-')) {
            $prefix = preg_replace('/-site-p\d+\.md$/', '', $sf);
            $f = glob("var/seo/dzen/{$prefix}-dzen-*.md") ?: [];
            return $f !== [] ? basename($f[0]) : "var/seo/dzen/{$prefix}-dzen-*.md";
        }
        if (str_starts_with($sf, 'guide-')) {
            return 'var/seo/guides/' . str_replace('-site.md', '-dzen.md', $sf);
        }

        return $sf;
    }

    /** /{locale}/brands/{slug} → brand.id (суммирует локали; null для прочих страниц). */
    private function resolveBrandId(string $pageUrl): ?int
    {
        if (!preg_match('~/(?:[a-z]{2})/brands/([^/?#]+)~', $pageUrl, $m)) {
            return null;
        }
        $id = $this->db->fetchOne('SELECT id FROM brand WHERE slug = :slug', ['slug' => urldecode($m[1])]);

        return $id === false ? null : (int) $id;
    }
}
