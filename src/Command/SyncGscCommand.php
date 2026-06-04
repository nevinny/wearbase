<?php

namespace App\Command;

use App\Service\Gsc\GscClient;
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
 *  2. URL Inspection: лимит Google 2000/день → cap 1500. Приоритет — свежеопубликованные
 *     дрипом (published_at за 7 дней, главный риск неиндексации), остаток — round-robin
 *     по last_checked_at (давно не проверенные). Полный обход базы ~4 дня.
 *  3. --report: алерты в лог (низкий indexed_ratio свежих, обвал показов день-к-дню).
 *
 * FAIL-OPEN: без кредов — лог и exit 0. GSC никогда не тормозит дрип-публикацию.
 *
 *   0 6 * * * cd /path && php bin/console app:gsc:sync --report --no-debug >> var/log/gsc.log 2>&1
 */
#[AsCommand(
    name: 'app:gsc:sync',
    description: 'GSC: Search Analytics + покрытие индекса → gsc_page_stats / gsc_index_status',
)]
class SyncGscCommand extends Command
{
    private const INSPECT_DAILY_CAP = 1500;  // лимит Google 2000/день, держим запас
    private const ANALYTICS_DAYS    = 7;     // тянем окно (upsert — повторы дёшевы)
    private const FRESH_DAYS        = 7;     // «свежие» для приоритета и алертов

    public function __construct(
        private readonly GscClient  $gsc,
        private readonly Connection $db,
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

    private function syncAnalytics(SymfonyStyle $io): void
    {
        // Лаг GSC ~2-3 дня: окно [сегодня-2-N, сегодня-2]
        $to   = (new \DateTime('-2 days'));
        $from = (new \DateTime(sprintf('-%d days', 2 + self::ANALYTICS_DAYS)));

        $rows = $this->gsc->searchAnalyticsByPage($from, $to);
        $io->text(sprintf('Search Analytics: %d строк (%s … %s)', count($rows), $from->format('Y-m-d'), $to->format('Y-m-d')));

        $upserted = 0;
        foreach ($rows as $row) {
            if ($row['page'] === '' || $row['date'] === '') {
                continue;
            }
            $this->db->executeStatement(
                'INSERT INTO gsc_page_stats (page_url, brand_id, day, impressions, clicks, position, query)
                 VALUES (:url, :brand_id, :day, :imp, :clicks, :pos, NULL)
                 ON DUPLICATE KEY UPDATE impressions = :imp, clicks = :clicks, position = :pos, brand_id = :brand_id',
                [
                    'url'      => mb_substr($row['page'], 0, 512),
                    'brand_id' => $this->resolveBrandId($row['page']),
                    'day'      => $row['date'],
                    'imp'      => $row['impressions'],
                    'clicks'   => $row['clicks'],
                    'pos'      => $row['position'],
                ],
            );
            $upserted++;
        }
        $io->text("Upsert в gsc_page_stats: {$upserted}");
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

        // Приоритет 2: round-robin по давности проверки
        $rest = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug FROM brand b
             LEFT JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.status = 'active'
               AND (s.last_checked_at IS NULL OR s.last_checked_at < :today)
             ORDER BY s.last_checked_at IS NULL DESC, s.last_checked_at ASC
             LIMIT " . max(0, $cap - count($fresh)),
            ['today' => (new \DateTime('today'))->format('Y-m-d H:i:s')],
        );

        $targets = [];
        foreach (array_merge($fresh, $rest) as $row) {
            $targets[(int) $row['id']] = (string) $row['slug']; // дедуп по brand_id
        }
        $targets = array_slice($targets, 0, $cap, preserve_keys: true);

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

            $this->db->executeStatement(
                'INSERT INTO gsc_index_status (brand_id, page_url, coverage_state, indexed, last_checked_at)
                 VALUES (:brand_id, :url, :coverage, :indexed, NOW())
                 ON DUPLICATE KEY UPDATE page_url = :url, coverage_state = :coverage, indexed = :indexed, last_checked_at = NOW()',
                [
                    'brand_id' => $brandId,
                    'url'      => $url,
                    'coverage' => $result['coverageState'],
                    'indexed'  => $result['indexed'] ? 1 : 0,
                ],
            );
            $checked++;
            $indexed += $result['indexed'] ? 1 : 0;
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

        // Общая индексация проверенных
        $total = $this->db->fetchAssociative('SELECT COUNT(*) c, COALESCE(SUM(indexed),0) idx FROM gsc_index_status');
        if ($total && (int) $total['c'] > 0) {
            $lines[] = sprintf('Индексация: %d/%d (%.0f%%)', $total['idx'], $total['c'], 100 * $total['idx'] / $total['c']);
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

        foreach ($lines as $line) {
            $io->text($line);
        }
        foreach ($alerts as $alert) {
            $io->warning($alert);
        }

        // Телеграм: алерты всегда, ежедневная сводка — вместе с ними
        if ($alerts !== [] && $this->notifier->isEnabled()) {
            try {
                $this->notifier->send("<b>GSC wearbase.ru</b>\n" . implode("\n", array_merge($alerts, $lines)));
            } catch (\Throwable) {
                // нотификация не должна ронять синк
            }
        }
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
