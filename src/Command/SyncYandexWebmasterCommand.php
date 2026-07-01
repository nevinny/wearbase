<?php

namespace App\Command;

use App\Service\Yandex\YandexWebmasterClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Синк Яндекс.Вебмастера (cron 1 раз в день) — RU-аналог app:gsc:sync:
 *  1. search-urls/in-search/samples → yandex_index_status: бренды, что СЕЙЧАС в поиске
 *     Яндекса (url → brand по slug). Coverage Яндекса не заморожен (в отличие от GSC).
 *  2. search-queries/popular → yandex_query_stats: TOP запросов (показы/клики/позиция) по RU.
 *  3. --report: сводка + алерт в TG.
 *
 * FAIL-OPEN: без токена — лог и exit 0 (мониторинг не настроен ≠ ошибка пайплайна).
 *
 *   0 7 * * * cd /path && php bin/console app:yandex:sync --report --no-debug >> var/log/yandex.log 2>&1
 */
#[AsCommand(
    name: 'app:yandex:sync',
    description: 'Яндекс.Вебмастер: страницы в поиске + популярные запросы → yandex_index_status / yandex_query_stats',
)]
class SyncYandexWebmasterCommand extends Command
{
    private const URLS_CAP = 5000;   // потолок примеров страниц в поиске за прогон

    public function __construct(
        private readonly YandexWebmasterClient $ya,
        private readonly Connection $db,
        private readonly \App\Notification\AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('urls-only',    null, InputOption::VALUE_NONE, 'Только страницы в поиске')
            ->addOption('queries-only', null, InputOption::VALUE_NONE, 'Только популярные запросы')
            ->addOption('report',       null, InputOption::VALUE_NONE, 'Сводка/алерт в TG')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Яндекс.Вебмастер · синк');

        if (!$this->ya->isConfigured()) {
            $io->warning('Яндекс.Вебмастер не настроен (YANDEX_WEBMASTER_API_KEY / YANDEX_WEBMASTER_HOST) — пропускаем.');
            return Command::SUCCESS;
        }

        try {
            $io->text(sprintf('user_id=%d · host_id=%s', $this->ya->userId(), $this->ya->hostId()));
            if (!$input->getOption('queries-only')) {
                $this->syncInSearch($io);
            }
            if (!$input->getOption('urls-only')) {
                $this->syncQueries($io);
            }
            if ($input->getOption('report')) {
                $this->report($io);
            }
        } catch (\Throwable $e) {
            $io->error('Яндекс.Вебмастер sync: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** Страницы в поиске Яндекса → yandex_index_status (только положительные метки; выборка не исчерпывающая). */
    private function syncInSearch(SymfonyStyle $io): void
    {
        $samples = $this->ya->urlsInSearch(self::URLS_CAP);
        $io->text(sprintf('search-urls/in-search: %d примеров', count($samples)));

        $marked = 0;
        foreach ($samples as $s) {
            $brandId = $this->resolveBrandId($s['url']);
            if ($brandId === null) {
                continue; // не-брендовая страница (блог/лендинг) — в этой таблице не трекаем
            }
            $this->db->executeStatement(
                'INSERT INTO yandex_index_status (brand_id, page_url, in_search, last_checked_at, first_seen_at)
                 VALUES (:brand_id, :url, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE page_url = :url, in_search = 1, last_checked_at = NOW(),
                     first_seen_at = COALESCE(first_seen_at, NOW())',
                ['brand_id' => $brandId, 'url' => mb_substr($s['url'], 0, 512)],
            );
            $marked++;
        }
        $io->text("Помечено брендов в поиске Яндекса: {$marked}");
    }

    /** TOP популярных запросов → yandex_query_stats. */
    private function syncQueries(SymfonyStyle $io): void
    {
        $queries = $this->ya->popularQueries(500);
        $io->text(sprintf('search-queries/popular: %d запросов', count($queries)));

        $upserted = 0;
        foreach ($queries as $q) {
            if ($q['query'] === '' || $q['dateTo'] === null) {
                continue;
            }
            $this->db->executeStatement(
                'INSERT INTO yandex_query_stats (query_text, shows, clicks, position, date_from, date_to)
                 VALUES (:q, :shows, :clicks, :pos, :df, :dt)
                 ON DUPLICATE KEY UPDATE shows = :shows, clicks = :clicks, position = :pos, date_from = :df',
                [
                    'q'      => mb_substr($q['query'], 0, 255),
                    'shows'  => $q['shows'],
                    'clicks' => $q['clicks'],
                    'pos'    => $q['position'],
                    'df'     => $q['dateFrom'],
                    'dt'     => $q['dateTo'],
                ],
            );
            $upserted++;
        }
        $io->text("Upsert в yandex_query_stats: {$upserted}");
    }

    private function report(SymfonyStyle $io): void
    {
        $io->section('Отчёт');
        $lines = [];

        $inSearch = (int) $this->db->fetchOne('SELECT COUNT(*) FROM yandex_index_status WHERE in_search = 1');
        $active   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM brand WHERE status = 'active'");
        $lines[] = sprintf('В поиске Яндекса: %d брендов (из %d active)', $inSearch, $active);

        $q = $this->db->fetchAssociative(
            'SELECT COUNT(*) c, COALESCE(SUM(shows),0) shows, COALESCE(SUM(clicks),0) clicks
             FROM yandex_query_stats WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)',
        );
        if ($q && (int) $q['c'] > 0) {
            $lines[] = sprintf('Запросы (неделя): %d фраз · показы %d · клики %d', $q['c'], $q['shows'], $q['clicks']);
        }

        foreach ($lines as $l) {
            $io->text($l);
        }

        if ($this->notifier->isEnabled()) {
            try {
                $this->notifier->send("<b>Яндекс.Вебмастер wearbase.ru</b>\n" . implode("\n", $lines));
            } catch (\Throwable) {
                // нотификация не должна ронять синк
            }
        }
    }

    /** /{locale}/brands/{slug} → brand.id (null для прочих страниц). */
    private function resolveBrandId(string $pageUrl): ?int
    {
        if (!preg_match('~/(?:[a-z]{2})/brands/([^/?#]+)~', $pageUrl, $m)) {
            return null;
        }
        $id = $this->db->fetchOne('SELECT id FROM brand WHERE slug = :slug', ['slug' => urldecode($m[1])]);

        return $id === false ? null : (int) $id;
    }
}
