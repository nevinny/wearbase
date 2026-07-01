<?php

namespace App\Command;

use App\Service\Seo\TrafficPotentialCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Калькулятор потенциала трафика (Яндекс): сколько кликов ПОЛУЧАЕМ vs МОГЛИ БЫ.
 * Логика в TrafficPotentialCalculator (общая с админ-панелью /admin/yandex-dynamics).
 * Трафик = клики (не CTR); потенциал = показы × CTR(целевая позиция).
 *
 *   php bin/console app:seo:traffic-potential
 */
#[AsCommand(
    name: 'app:seo:traffic-potential',
    description: 'Потенциал трафика Яндекса: captured (клики) vs потенциал при топ-3 vs адресуемый рынок',
)]
class TrafficPotentialCommand extends Command
{
    public function __construct(private readonly TrafficPotentialCalculator $calc)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Потенциал трафика · Яндекс');

        $d = $this->calc->compute();
        if ($d === null) {
            $io->warning('Нет данных yandex_query_stats — запусти app:yandex:sync.');
            return Command::SUCCESS;
        }

        $io->section("Окно {$d['window']} · запросов {$d['queries']}");
        $io->text(sprintf('Captured (реальные клики на ранжируемых запросах): <info>%d</info>', $d['captured']));
        $io->text(sprintf('Потенциал при топ-3 на ТЕХ ЖЕ запросах: <info>%d</info> кликов', $d['potentialTop3']));
        $io->text(sprintf('  → упущено из-за позиций 9–13: <comment>%d</comment> кликов (×%.1f)', $d['missed'], $d['factor']));
        $io->text(sprintf('Адресуемый рынок (весь Wordstat-спрос %d/мес × CTR топ-3): <info>%s</info> кликов/мес (верхний предел)',
            $d['demand'], number_format($d['addressable'], 0, '.', ' ')));

        $io->section('Топ-15 запросов по упущенному трафику (дожать позицию → клики)');
        $io->table(
            ['Запрос', 'Показы', 'Поз', 'Сейчас~', 'Топ-3~', '+Упущено'],
            array_map(static fn($o) => [
                mb_substr($o['q'], 0, 40), $o['shows'], sprintf('%.1f', $o['pos']),
                sprintf('%.1f', $o['now']), sprintf('%.1f', $o['top3']), sprintf('%.1f', $o['missed']),
            ], array_slice($d['opportunities'], 0, 15)),
        );

        return Command::SUCCESS;
    }
}
