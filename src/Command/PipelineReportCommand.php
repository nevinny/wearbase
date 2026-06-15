<?php

namespace App\Command;

use App\Notification\AdminNotifier;
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
 * Сводка RAG-конвейера в Telegram: парсинг/генерация/ключевики/готовность к пушу + темпы за час.
 *
 * Частоту задаёт планировщик (таблица scheduled_command, /admin → «Крон (расписание)»),
 * а не сама команда — внутренний throttle убран: каждый запуск шлёт сводку.
 */
#[AsCommand(
    name: 'app:report:pipeline',
    description: 'Сводка RAG-конвейера в Telegram (для крона раз в 3 часа)',
)]
class PipelineReportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl = null,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Не слать в Telegram, только вывести');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $one = fn(string $sql) => (int) $this->db->fetchOne($sql);

        // Темп за час — от MAX(ts) самой стадии (TZ-независимо)
        // Темп за РЕАЛЬНЫЙ последний час (NOW), а не от MAX(ts): иначе замороженная стадия
        // вечно показывает призрачный «+N/ч» из своего последнего активного часа (напр.
        // генерация +25/ч 3-дневной давности при выключенном .43). cutoff считаем в PHP — той
        // же зоной, что пишутся timestamp'ы (new \DateTime() в командах), без MySQL NOW()-перекоса.
        $rate = function (string $table, string $col): int {
            $since = (new \DateTime('-1 hour'))->format('Y-m-d H:i:s');
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE {$col} >= :since",
                ['since' => $since],
            );
        };

        $discovered = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE discovered_at IS NOT NULL");
        $urlPending = $one("SELECT COUNT(*) FROM brand_source_url WHERE status='pending'");
        $urlFetched = $one("SELECT COUNT(*) FROM brand_source_url WHERE status='fetched'");
        $docs       = $one("SELECT COUNT(*) FROM brand_source_document");
        $embedded   = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='embedded'");
        $done       = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='done'");
        $grounded   = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE grounded=1");
        $deferred   = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='deferred'");
        $kwChecked  = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE keywords_status IS NOT NULL");
        $kwLeft     = $one("SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id WHERE b.status IN ('active','new') AND p.keywords_status IS NULL");
        $faqDone    = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE faq_status='done'");
        $readyPush  = $one("SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
            WHERE p.status='done' AND p.pushed_at IS NULL AND p.push_attempts < 3
              AND p.faq_status IN ('done','skipped') AND p.keywords_status IN ('found','not_found')
              AND b.description IS NOT NULL AND b.description != ''
              AND b.meta_title IS NOT NULL AND b.meta_title != ''
              AND b.meta_description IS NOT NULL AND b.meta_description != ''");
        $pushed     = $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE pushed_at IS NOT NULL");

        $dHr = $rate('brand_rag_pipeline', 'discovered_at');
        $fHr = $rate('brand_source_url', 'fetched_at');
        $gHr = $rate('brand_rag_pipeline', 'generated_at');
        $kHr = $rate('brand_rag_pipeline', 'keywords_checked_at');

        // ETA осушения очереди fetch: pending / темп скачивания. Прогноз грубый
        // (crawl ещё может доливать), но даёт порядок. fHr=0 → нечем делить.
        if ($fHr > 0 && $urlPending > 0) {
            $etaMin = (int) round($urlPending / $fHr * 60);
            $eta = $etaMin >= 60 ? sprintf('~%dч %02dм', intdiv($etaMin, 60), $etaMin % 60) : sprintf('~%dм', $etaMin);
        } else {
            $eta = $urlPending === 0 ? 'очередь пуста' : '—';
        }

        // --- Публикации на проде (TG с прода заблокирован → тянем сюда по агент-API) ---
        $pubToday = $pubTotal = $pubWait = $pubLast = '—';
        try {
            if (trim((string) $this->prodApiUrl) !== '') {
                $p = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                    'headers' => ['X-Agent-Token' => (string) $this->agentToken], 'timeout' => 6,
                ])->toArray(false);
                $pubToday = $p['published_today'] ?? '—';
                $pubTotal = $p['published_total'] ?? '—';
                $pubWait  = $p['queue_pending'] ?? '—';
                $pubLast  = $p['last_published'] ?? '—';
            }
        } catch (\Throwable) {
            // прод недоступен — оставляем «—»
        }
        $gscChecked = $one("SELECT COUNT(*) FROM gsc_index_status");
        $gscIndexed = $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status");

        $msg = sprintf(
            "<b>Конвейер · %s</b>\n\n" .
            "<b>Парсинг:</b> discovered %d (+%d/ч) · URL: %d ждут / %d скачано (+%d/ч) · доков %d\n" .
            "<b>⏳ ETA осушения fetch:</b> %s\n" .
            "<b>Генерация:</b> done %d (+%d/ч), grounded %d · FAQ %d · в очереди %d · deferred %d\n" .
            "<b>Ключевики:</b> %d опрошено (+%d/ч) · осталось %d\n" .
            "<b>Пуш:</b> готово %d · доставлено %d\n" .
            "<b>📢 Публикации (прод):</b> сегодня %s · всего %s · ждут %s (посл. %s)\n" .
            "<b>GSC:</b> проверено %d · в индексе %d",
            (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m H:i'),
            $discovered, $dHr, $urlPending, $urlFetched, $fHr, $docs,
            $eta,
            $done, $gHr, $grounded, $faqDone, $embedded, $deferred,
            $kwChecked, $kHr, $kwLeft,
            $readyPush, $pushed,
            $pubToday, $pubTotal, $pubWait, $pubLast,
            $gscChecked, $gscIndexed,
        );

        $io->text(strip_tags($msg));

        if (!$input->getOption('stdout-only')) {
            if (!$this->notifier->isEnabled()) {
                $io->warning('Telegram не настроен (ADMIN_TELEGRAM_CHAT_ID).');
                return Command::SUCCESS; // fail-open
            }

            $this->notifier->send($msg);
        }

        return Command::SUCCESS;
    }
}
