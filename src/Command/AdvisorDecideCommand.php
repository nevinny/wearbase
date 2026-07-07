<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Advisor\DecisionMaker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Шаг 4 «Выбор» цикла советника (docs/advisor.md): детерминированная классификация proposed-идей
 * по риску a|b|c и раскладка по статусам через DecisionMaker (без LLM). Запускается после
 * app:advisor:tick (наполняет бэклог) в cron-диспетчере.
 *
 *   php bin/console app:advisor:decide            # применить решения (flush)
 *   php bin/console app:advisor:decide --dry-run  # классифицировать и показать, НЕ сохранять
 */
#[AsCommand(name: 'app:advisor:decide', description: 'Классификация proposed-идей по риску a|b|c и раскладка статусов (без LLM)')]
class AdvisorDecideCommand extends Command
{
    public function __construct(private readonly DecisionMaker $decisionMaker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Классифицировать и показать, но не сохранять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->decisionMaker->decide($dryRun);

        if ($result['halted']) {
            $io->warning('HALT-файл var/agent/HALT присутствует — решения не принимались (kill-switch).');
            return Command::SUCCESS;
        }

        if ($result['decisions'] === []) {
            $io->success('Бэклог proposed пуст — решать нечего.');
            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn(array $d) => [
                $d['id'] ?? '—',
                $d['class'],
                $d['status'],
                $d['reason'],
            ],
            $result['decisions'],
        );
        $io->table(['idea', 'class', 'status', 'reason'], $rows);

        $io->writeln(sprintf('Обработано: %d · в работе: %d/%d', $result['processed'], $result['wip_used'], DecisionMaker::MAX_IN_WORK));

        $dryRun
            ? $io->note('dry-run: изменения НЕ сохранены.')
            : $io->success('Решения применены.');

        return Command::SUCCESS;
    }
}
