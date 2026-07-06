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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $agentSecret,
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
            $this->syncHistory($io);
            $this->pushDripHealth($io);
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

    /** Дневной ряд (страницы в поиске + показы/клики) → yandex_history. Окно 400 дн (upsert). */
    private function syncHistory(SymfonyStyle $io): void
    {
        $to   = new \DateTime('today');
        $from = (new \DateTime('today'))->modify('-400 days');
        $pages  = $this->ya->inSearchHistory($from, $to);
        $totals = $this->ya->queryTotalsHistory($from, $to);

        $days = array_unique(array_merge(array_keys($pages), array_keys($totals['shows']), array_keys($totals['clicks'])));
        $n = 0;
        foreach ($days as $day) {
            if ($day === '') {
                continue;
            }
            $this->db->executeStatement(
                'INSERT INTO yandex_history (day, pages_in_search, shows, clicks) VALUES (:d, :p, :s, :c)
                 ON DUPLICATE KEY UPDATE
                     pages_in_search = COALESCE(:p, pages_in_search),
                     shows = COALESCE(:s, shows),
                     clicks = COALESCE(:c, clicks)',
                ['d' => $day, 'p' => $pages[$day] ?? null, 's' => $totals['shows'][$day] ?? null, 'c' => $totals['clicks'][$day] ?? null],
            );
            $n++;
        }
        $io->text("История: {$n} дней в yandex_history");
    }

    /**
     * Авто-guard дрипа: считает multiplier по динамике pages_in_search (усваивает ли Яндекс
     * новые страницы) и пушит на прод (drip_health) через agent-API. Дрип на проде троттлит темп.
     * Растёт → ×1.0; стоит → ×0.5; падает → ×0.25. Мало данных / прод не настроен → не пушим (fail-open).
     */
    private function pushDripHealth(SymfonyStyle $io): void
    {
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->agentToken) === '') {
            return;
        }
        $latest = $this->db->fetchOne('SELECT pages_in_search FROM yandex_history WHERE pages_in_search IS NOT NULL ORDER BY day DESC LIMIT 1');
        $prior  = $this->db->fetchOne('SELECT pages_in_search FROM yandex_history WHERE pages_in_search IS NOT NULL AND day <= DATE_SUB(CURDATE(), INTERVAL 14 DAY) ORDER BY day DESC LIMIT 1');
        if ($latest === false || $prior === false || (int) $prior === 0) {
            $io->text('Drip-health: недостаточно данных — не пушим (дрип fail-open).');
            return;
        }
        $latest = (int) $latest;
        $prior  = (int) $prior;
        $delta  = $latest - $prior;
        $multiplier = $delta > 0 ? 1.0 : ($delta === 0 ? 0.5 : 0.25);
        $note = sprintf('pages %d→%d (%+d за 14д)', $prior, $latest, $delta);

        $body = json_encode(['multiplier' => $multiplier, 'pages_in_search' => $latest, 'note' => $note], JSON_UNESCAPED_UNICODE);
        try {
            $resp = $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/drip-health', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Agent-Token' => (string) $this->agentToken,
                    'X-Signature'   => hash_hmac('sha256', $body, (string) $this->agentSecret),
                ],
                'body'    => $body,
                'timeout' => 15,
            ]);
            $io->text(sprintf('Drip-health → прод: ×%.2f (%s) [HTTP %d]', $multiplier, $note, $resp->getStatusCode()));
        } catch (\Throwable $e) {
            $io->warning('Drip-health push не прошёл: ' . mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function report(SymfonyStyle $io): void
    {
        $io->section('Отчёт');
        $lines = [];

        $inSearch = (int) $this->db->fetchOne('SELECT COUNT(*) FROM yandex_index_status WHERE in_search = 1');
        $lines[]  = $this->activeCountLine($inSearch);

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

    /**
     * Строка «В поиске Яндекса: N (из M active)» — знаменатель active берём с прода
     * (agent-API, publish-stats.active_total): числитель (страницы прода) и знаменатель
     * иначе оказываются из разных БД (dev vs прод). Прод недоступен/поля нет — честный
     * fallback на локальный COUNT с явной подписью «в dev-БД».
     */
    private function activeCountLine(int $inSearch): string
    {
        if (trim((string) $this->prodApiUrl) !== '' && trim((string) $this->agentToken) !== '') {
            try {
                $d = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                    'headers' => ['X-Agent-Token' => (string) $this->agentToken],
                    'timeout' => 8,
                ])->toArray(false);
                if (isset($d['active_total'])) {
                    return sprintf('В поиске Яндекса: %d брендов (из %d active на проде)', $inSearch, (int) $d['active_total']);
                }
            } catch (\Throwable) {
                // прод недоступен — fallback ниже
            }
        }

        $active = (int) $this->db->fetchOne("SELECT COUNT(*) FROM brand WHERE status = 'active'");
        return sprintf('В поиске Яндекса: %d брендов (из %d active в dev-БД)', $inSearch, $active);
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
