<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Advisor\AgentSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Агент-режим советника (docs/advisor.md §Мозг, Phase 4 «on-demand»): ЗАДАТЬ ВОПРОС —
 * ReAct-агент сам решает, какие данные запросить из БД, анализирует и выдаёт ответ.
 * В отличие от app:advisor:ask не требует предварительного снимка (StateSnapshot) —
 * агент сам лезет в БД за метриками, ищет аномалии, строит выборки.
 *
 * Использует ReAct-цикл: описывает схему → проектирует SQL → выполняет → анализирует →
 * уточняет → финальный ответ. Каждый шаг логируется для аудита.
 *
 *   php bin/console app:advisor:ask-agent "Почему упал трафик на этой неделе?"
 *   php bin/console app:advisor:ask-agent "..." --plain   # только текст ответа (для TG-бота)
 */
#[AsCommand(name: 'app:advisor:ask-agent', description: 'Задать вопрос агенту советника — ReAct-цикл с SQL-запросами к БД (read-only)')]
class AdvisorAskAgentCommand extends Command
{
    public function __construct(
        private readonly AgentSupervisor $agent,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'Вопрос владельца агенту')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Печатать только текст ответа, без оформления (для TG-бота)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $plain    = (bool) $input->getOption('plain');
        $question = trim((string) $input->getArgument('question'));

        if ($question === '') {
            $io->error('Пустой вопрос.');
            return Command::FAILURE;
        }

        try {
            if (!$plain) {
                $io->section('Вопрос');
                $io->text($question);
                $io->section('Агент думает…');
            }

            $answer = $this->agent->run($question);
        } catch (\Throwable $e) {
            $io->error('Агент недоступен: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $answer = trim($answer);
        if ($answer === '') {
            $io->error('Агент вернул пустой ответ.');
            return Command::FAILURE;
        }

        if ($plain) {
            $output->writeln($answer);
            return Command::SUCCESS;
        }

        $io->writeln($answer);

        return Command::SUCCESS;
    }
}
