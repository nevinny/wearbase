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

/**
 * Недельный дайджест видимости в поиске (неделя к неделе) в Telegram.
 * Всё из локальной БД: снимки метрик пишет ежедневно `app:advisor:snapshot`
 * (таблица state_snapshot), трафик Google — gsc_page_stats (синк с Mac).
 * Сравнивает последний снимок с ближайшим к «7 дней назад».
 * ⚠️ Запускать ТОЛЬКО с Mac — Telegram недоступен с .43 и с прода. Крон Mac: 0 10 * * 1
 *
 *   php bin/console app:report:weekly            # снять + отправить в TG
 *   php bin/console app:report:weekly --stdout-only
 */
#[AsCommand(name: 'app:report:weekly', description: 'Недельный дайджест видимости в поиске (w-w) в Telegram — запускать с Mac')]
class WeeklyReportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
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

        // --- Снимки метрик: последний и ближайший к «7 дней назад» ---
        $latest = $this->db->fetchAssociative(
            'SELECT created_at, metrics FROM state_snapshot ORDER BY created_at DESC LIMIT 1',
        );
        if (!$latest) {
            $io->warning('Нет снимков в state_snapshot — сначала должен отработать app:advisor:snapshot.');
            return Command::SUCCESS;
        }
        // Ближайший снимок с датой ≤ (последняя − 7 дней); если такого нет — самый старый.
        $prev = $this->db->fetchAssociative(
            "SELECT created_at, metrics FROM state_snapshot
             WHERE created_at <= DATE_SUB(:t, INTERVAL 7 DAY)
             ORDER BY created_at DESC LIMIT 1",
            ['t' => $latest['created_at']],
        ) ?: $this->db->fetchAssociative(
            'SELECT created_at, metrics FROM state_snapshot ORDER BY created_at ASC LIMIT 1',
        );

        $now  = json_decode((string) $latest['metrics'], true) ?: [];
        $then = json_decode((string) ($prev['metrics'] ?? '{}'), true) ?: [];

        $nowDate  = (new \DateTime((string) $latest['created_at']))->format('d.m');
        $prevDate = isset($prev['created_at'])
            ? (new \DateTime((string) $prev['created_at']))->format('d.m')
            : '—';

        // --- Трафик Google по неделям (gsc_page_stats отстаёт ~3д — якорь = MAX(day)) ---
        $anchor = $this->db->fetchOne('SELECT MAX(day) FROM gsc_page_stats');
        $gscThis = $gscPrev = null;
        if ($anchor) {
            $agg = fn(string $from, string $to) => $this->db->fetchAssociative(
                'SELECT COALESCE(SUM(impressions),0) imp, COALESCE(SUM(clicks),0) clk, ROUND(AVG(position),1) pos
                 FROM gsc_page_stats WHERE day BETWEEN :f AND :t',
                ['f' => $from, 't' => $to],
            );
            $a = new \DateTime((string) $anchor);
            $gscThis = $agg((clone $a)->modify('-6 day')->format('Y-m-d'), $a->format('Y-m-d'));
            $gscPrev = $agg((clone $a)->modify('-13 day')->format('Y-m-d'), (clone $a)->modify('-7 day')->format('Y-m-d'));
        }

        // --- Форматирование дельт ---
        $delta = function (?int $a, ?int $b): string {
            if ($a === null || $b === null) {
                return '—';
            }
            $d = $b - $a;
            if ($d === 0) {
                return sprintf('%s (±0)', number_format($b, 0, '.', ' '));
            }
            $pct = $a > 0 ? sprintf(' %+d%%', (int) round(100 * $d / $a)) : '';
            return sprintf('%s → <b>%s</b> (%+d%s)',
                number_format($a, 0, '.', ' '), number_format($b, 0, '.', ' '), $d, $pct);
        };
        $g = fn(array $m, string $k): ?int => isset($m[$k]) ? (int) $m[$k] : null;

        // GSC-трафик отдельно (позиция — float, «меньше = лучше»)
        $gscTraffic = '';
        if ($gscThis && $gscPrev) {
            $posNote = '';
            if ($gscThis['pos'] !== null && $gscPrev['pos'] !== null) {
                $arrow = (float) $gscThis['pos'] <= (float) $gscPrev['pos'] ? '↑ лучше' : '↓ глубже';
                $posNote = sprintf("\n• Средняя позиция: %s → %s (%s)", $gscPrev['pos'], $gscThis['pos'], $arrow);
            }
            $gscTraffic = sprintf(
                "\n• Показы за 7д: %s\n• Клики за 7д: %s%s",
                $delta((int) $gscPrev['imp'], (int) $gscThis['imp']),
                $delta((int) $gscPrev['clk'], (int) $gscThis['clk']),
                $posNote,
            );
        }

        // --- Наблюдения из данных (без хардкода советов) ---
        $notes = [];
        $pub = $g($now, 'prod_published_total');
        $idx = $g($now, 'gsc_indexed');
        if ($pub !== null && $idx !== null && $pub > $idx) {
            $notes[] = sprintf('• Опубликовано %d, в индексе Google %d → ~%d не в индексе (кандидаты на Indexing API ping).', $pub, $idx, $pub - $idx);
        }
        if ($gscThis && (int) $gscThis['imp'] > 0) {
            $ctr = 100 * (int) $gscThis['clk'] / (int) $gscThis['imp'];
            $notes[] = sprintf('• CTR Google за неделю ≈ %.1f%% (клики/показы).', $ctr);
        }
        $notesTxt = $notes ? "\n\n<b>👀 Наблюдения:</b>\n" . implode("\n", $notes) : '';

        $msg = sprintf(
            "<b>🔎 Дайджест видимости · %s (неделя к неделе)</b>\n<i>сравнение с %s</i>\n\n" .
            "<b>🟦 Google (GSC)</b>\n" .
            "• В индексе: %s\n" .
            "• Когда-либо в индексе: %s\n" .
            "• Проверено страниц: %s%s\n\n" .
            "<b>🟨 Яндекс</b>\n" .
            "• В поиске: %s\n" .
            "• Топ-500 фраз, показы: %s\n" .
            "• Топ-500 фраз, клики: %s\n\n" .
            "<b>📦 Публикации на проде</b>\n" .
            "• Всего брендов: %s%s",
            $nowDate, $prevDate,
            $delta($g($then, 'gsc_indexed'), $g($now, 'gsc_indexed')),
            $delta($g($then, 'gsc_ever'), $g($now, 'gsc_ever')),
            $delta($g($then, 'gsc_checked'), $g($now, 'gsc_checked')),
            $gscTraffic,
            $delta($g($then, 'yandex_in_search'), $g($now, 'yandex_in_search')),
            $delta($g($then, 'yandex_shows'), $g($now, 'yandex_shows')),
            $delta($g($then, 'yandex_clicks'), $g($now, 'yandex_clicks')),
            $delta($g($then, 'prod_published_total'), $g($now, 'prod_published_total')),
            $notesTxt,
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
