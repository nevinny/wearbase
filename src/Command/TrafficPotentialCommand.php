<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Калькулятор потенциала трафика (Яндекс): сколько кликов ПОЛУЧАЕМ vs МОГЛИ БЫ.
 * Вход: yandex_query_stats (показы/клики/позиция по запросам, где уже ранжируемся) +
 * brand_keyword (Wordstat-спрос = адресуемый рынок). Трафик ≠ CTR: трафик = клики,
 * CTR = клики/показы; потенциал = показы × CTR(целевая позиция).
 *
 *   php bin/console app:seo:traffic-potential
 */
#[AsCommand(
    name: 'app:seo:traffic-potential',
    description: 'Потенциал трафика Яндекса: captured (клики) vs потенциал при топ-3 vs адресуемый рынок (Wordstat)',
)]
class TrafficPotentialCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    /** Кривая CTR по позиции в Яндексе (приближение). */
    private function ctr(float $pos): float
    {
        $p = (int) round($pos);
        return match (true) {
            $p <= 1  => 0.28,
            $p === 2 => 0.15,
            $p === 3 => 0.10,
            $p === 4 => 0.07,
            $p === 5 => 0.05,
            $p === 6 => 0.04,
            $p === 7 => 0.03,
            $p === 8 => 0.025,
            $p === 9 => 0.02,
            $p <= 10 => 0.018,
            $p <= 20 => 0.008,
            default  => 0.003,
        };
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Потенциал трафика · Яндекс');

        $window = $this->db->fetchOne('SELECT MAX(date_to) FROM yandex_query_stats');
        if ($window === false || $window === null) {
            $io->warning('Нет данных yandex_query_stats — запусти app:yandex:sync.');
            return Command::SUCCESS;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT query_text, shows, clicks, position FROM yandex_query_stats WHERE date_to = :d',
            ['d' => $window],
        );

        $ctrTop3      = $this->ctr(3);
        $capturedReal = 0;   // реальные клики (измерено)
        $capturedEst  = 0.0; // оценка по CTR текущей позиции (для сопоставимости с потенциалом)
        $potentialTop3 = 0.0;
        $opps = [];

        foreach ($rows as $r) {
            $shows = (int) $r['shows'];
            $pos   = (float) $r['position'];
            $capturedReal += (int) $r['clicks'];
            $nowEst  = $shows * $this->ctr($pos);
            $top3Est = $shows * $ctrTop3;
            $capturedEst   += $nowEst;
            $potentialTop3 += $top3Est;
            $missed = $top3Est - $nowEst;
            if ($missed > 0.5) {
                $opps[] = ['q' => $r['query_text'], 'shows' => $shows, 'pos' => $pos, 'now' => $nowEst, 'top3' => $top3Est, 'missed' => $missed];
            }
        }

        // Адресуемый рынок: полный Wordstat-спрос × CTR топ-3 (если бы ранжировались в топ-3 по всему спросу)
        $demand = (int) $this->db->fetchOne('SELECT COALESCE(SUM(monthly_shows), 0) FROM brand_keyword');
        $addressable = $demand * $ctrTop3;

        usort($opps, static fn($a, $b) => $b['missed'] <=> $a['missed']);

        $io->section("Окно {$window} · запросов " . count($rows));
        $io->text(sprintf('Captured (реальные клики на ранжируемых запросах): <info>%d</info>', $capturedReal));
        $io->text(sprintf('Потенциал при топ-3 на ТЕХ ЖЕ запросах: <info>%d</info> кликов', (int) round($potentialTop3)));
        $io->text(sprintf('  → упущено из-за позиций 9–13: <comment>%d</comment> кликов (×%.1f)',
            (int) round($potentialTop3 - $capturedReal),
            $capturedReal > 0 ? $potentialTop3 / $capturedReal : 0));
        $io->text(sprintf('Адресуемый рынок (весь Wordstat-спрос %d/мес × CTR топ-3): <info>%s</info> кликов/мес потенциально',
            $demand, number_format($addressable, 0, '.', ' ')));

        $io->section('Топ-15 запросов по упущенному трафику (дожать позицию → клики)');
        $io->table(
            ['Запрос', 'Показы', 'Поз', 'Сейчас~', 'Топ-3~', '+Упущено'],
            array_map(static fn($o) => [
                mb_substr($o['q'], 0, 40),
                $o['shows'],
                sprintf('%.1f', $o['pos']),
                sprintf('%.1f', $o['now']),
                sprintf('%.1f', $o['top3']),
                sprintf('%.1f', $o['missed']),
            ], array_slice($opps, 0, 15)),
        );

        return Command::SUCCESS;
    }
}
