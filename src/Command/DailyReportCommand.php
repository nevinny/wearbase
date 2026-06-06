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
 * Ежедневный дайджест в Telegram: публикации на проде + индексация GSC.
 * ⚠️ Запускать ТОЛЬКО с Mac — Telegram заблокирован и с .43, и с прода regru.
 * Публикации тянем с прода по агент-API (/api/v1/publish-stats); GSC — из локальной БД
 * (синк делает крон на .43, данные ложатся сюда). Для крона Mac: 17 9 * * *
 *
 *   php bin/console app:report:daily            # снять + отправить в TG
 *   php bin/console app:report:daily --stdout-only
 */
#[AsCommand(name: 'app:report:daily', description: 'Ежедневный дайджест (публикации прода + GSC) в Telegram — запускать с Mac')]
class DailyReportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Не слать в Telegram, только вывести');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $one = fn(string $sql) => (int) $this->db->fetchOne($sql);

        // --- Публикации с прода (агент-API; TG с прода недоступен — поэтому тянем сюда) ---
        $pub = ['published_today' => '—', 'published_total' => '—', 'queue_pending' => '—', 'last_published' => '—'];
        try {
            if (trim((string) $this->prodApiUrl) !== '') {
                $d = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                    'headers' => ['X-Agent-Token' => (string) $this->agentToken],
                    'timeout' => 8,
                ])->toArray(false);
                $pub = array_merge($pub, array_intersect_key($d, $pub));
            }
        } catch (\Throwable) {
            // прод недоступен — оставляем «—»
        }

        // --- GSC (локальная БД; синк делает крон .43) ---
        $cohort = $this->db->fetchAssociative(
            "SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx FROM brand b
             JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at IS NOT NULL AND b.published_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)",
        ) ?: ['checked' => 0, 'idx' => 0];
        $gscChecked = $one("SELECT COUNT(*) FROM gsc_index_status");
        $gscIndexed = $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status");
        $gscLast    = $this->db->fetchOne("SELECT MAX(last_checked_at) FROM gsc_index_status") ?: '—';
        $cohortTxt  = (int) $cohort['checked'] > 0
            ? sprintf('%d/%d (%.0f%%)', $cohort['idx'], $cohort['checked'], 100 * $cohort['idx'] / max(1, (int) $cohort['checked']))
            : '— (нет когорты 14д+)';

        $msg = sprintf(
            "<b>📅 Дайджест · %s</b>\n\n" .
            "<b>Публикации (прод):</b> сегодня %s · всего %s · ждут %s\n" .
            "Последняя: %s\n\n" .
            "<b>GSC:</b> проверено %d · в индексе %d\n" .
            "Когорта 14д+ в индексе: %s\n" .
            "Последняя проверка: %s",
            (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'),
            $pub['published_today'], $pub['published_total'], $pub['queue_pending'],
            $pub['last_published'],
            $gscChecked, $gscIndexed,
            $cohortTxt,
            $gscLast,
        );

        $io->text(strip_tags($msg));
        if (!$input->getOption('stdout-only')) {
            if (!$this->notifier->isEnabled()) {
                $io->warning('Telegram не настроен (ADMIN_TELEGRAM_CHAT_ID).');
                return Command::SUCCESS;
            }
            $this->notifier->send($msg);
        }

        return Command::SUCCESS;
    }
}
