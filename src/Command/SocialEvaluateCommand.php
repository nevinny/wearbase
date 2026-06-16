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
 * Closed-loop (read-only): ранжирует рубрики по «движенческому» engagementScore
 * (saves/shares/link_taps весомее лайков, см. docs/marketing_instagram.md §7) за окно
 * и шлёт отчёт. Питается из social_post_metric — снимки метрик кладёт метрик-коллектор
 * по площадкам (зависит от аккаунтов, Ф0).
 *
 * TODO (когда пойдут реальные метрики): авто-масштабирование рубрик-победителей /
 * урезание лузеров в SocialPlanner. Сейчас намеренно не мутируем сетку (нет данных).
 */
#[AsCommand(name: 'app:social:evaluate', description: 'Отчёт по эффективности рубрик (closed-loop)')]
class SocialEvaluateCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Окно анализа, дней', '14')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить отчёт в ops-чат (Telegram)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $since = (new \DateTime("-{$days} days"))->format('Y-m-d H:i:s');

        // Берём ПОСЛЕДНИЙ снимок метрик каждого поста, агрегируем по рубрике.
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.rubric AS rubric,
                    COUNT(DISTINCT p.id) AS posts,
                    ROUND(AVG(m.saves*3 + m.shares*3 + m.link_taps*2 + m.comments), 1) AS avg_score,
                    SUM(m.link_taps) AS link_taps
             FROM social_post p
             JOIN social_post_metric m ON m.post_id = p.id
             JOIN (SELECT post_id, MAX(measured_at) mx FROM social_post_metric GROUP BY post_id) lm
                  ON lm.post_id = m.post_id AND lm.mx = m.measured_at
             WHERE p.published_at >= :since
             GROUP BY p.rubric
             ORDER BY avg_score DESC',
            ['since' => $since],
        );

        if ($rows === []) {
            $io->text("Нет метрик за {$days} дн. (метрик-коллектор ещё не наполнил social_post_metric).");
            return Command::SUCCESS;
        }

        $io->table(
            ['Рубрика', 'Постов', 'Ср. score', 'Клики'],
            array_map(static fn (array $r) => [$r['rubric'], $r['posts'], $r['avg_score'], $r['link_taps']], $rows),
        );

        if ($input->getOption('notify') && $this->notifier->isEnabled()) {
            $lines = array_map(
                static fn (array $r) => sprintf('• %s — score %s (%d постов, %d кликов)', $r['rubric'], $r['avg_score'], $r['posts'], $r['link_taps']),
                $rows,
            );
            try {
                $this->notifier->send("📊 <b>Соцсети: рубрики за {$days}д</b>\n" . implode("\n", $lines));
            } catch (\Throwable) {
                // отчёт не критичен
            }
        }

        $io->success(sprintf('Проанализировано рубрик: %d (окно %d дн.)', count($rows), $days));

        return Command::SUCCESS;
    }
}
