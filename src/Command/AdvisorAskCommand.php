<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\StateSnapshotRepository;
use App\Service\Advisor\AdvisorRag;
use App\Service\LlmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * On-demand режим советника (docs/advisor.md §Мозг, Phase 4 «on-demand»): ЗАДАТЬ ВОПРОС —
 * получить ответ на основе текущего состояния проекта (последний StateSnapshot) + базы знаний
 * каналов (AdvisorRag → topic_chunks). READ-ONLY: ничего не пишет в БД/TG.
 *
 * Ретрив принципов (роли idea/framing/case) граундит ответ; gemma (LlmService, local, think=false)
 * мэппит принципы на метрики состояния и отвечает свободным текстом. Снимка нет → предупреждаем,
 * но продолжаем (состояние пустое). Ретрив пуст → отвечаем без опоры на принципы.
 *
 *   php bin/console app:advisor:ask "Что важнее всего для роста трафика?"
 *   php bin/console app:advisor:ask "..." --role=framing   # сузить ретрив до одной роли
 */
#[AsCommand(name: 'app:advisor:ask', description: 'Задать вопрос советнику — ответ по состоянию проекта + базе знаний (read-only)')]
class AdvisorAskCommand extends Command
{
    public function __construct(
        private readonly StateSnapshotRepository $snapshots,
        private readonly AdvisorRag $rag,
        private readonly LlmService $llm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'Вопрос владельца советнику')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Сузить ретрив до одной роли (idea|framing|case)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $question = trim((string) $input->getArgument('question'));
        if ($question === '') {
            $io->error('Пустой вопрос.');
            return Command::FAILURE;
        }

        // Состояние: последний снимок. Нет — продолжаем с пустыми метриками (предупредив).
        $snap    = $this->snapshots->findLatest();
        $metrics = $snap?->getMetrics() ?? [];
        if ($snap === null) {
            $io->warning('Нет ни одного StateSnapshot — отвечаю без метрик состояния (app:advisor:snapshot соберёт их).');
        }

        // Ретрив принципов базы знаний. Опция --role сужает роли; иначе idea/framing/case.
        $roles = AdvisorRag::IDEA_ROLES;
        $role  = $input->getOption('role');
        if ($role !== null) {
            $role = trim((string) $role);
            if (!in_array($role, AdvisorRag::IDEA_ROLES, true)) {
                $io->error(sprintf('Неизвестная роль «%s». Допустимо: %s.', $role, implode(', ', AdvisorRag::IDEA_ROLES)));
                return Command::FAILURE;
            }
            $roles = [$role];
        }

        // Best-effort мозг: любой сбой (gemma недоступна/таймаут/пустой ретрив) — понятная
        // ошибка, не стектрейс.
        try {
            $chunks  = $this->rag->retrieve($question, $roles, 6);
            $context = $this->rag->formatContext($chunks);
            $answer  = $this->llm->generateAdvisorAnswer($question, $metrics, $context);
        } catch (\Throwable $e) {
            $io->error('Советник недоступен: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $answer = trim($answer);
        if ($answer === '') {
            $io->error('Советник вернул пустой ответ (gemma перегружена? повторите позже).');
            return Command::FAILURE;
        }

        $io->section('Вопрос');
        $io->text($question);
        $io->section('Ответ советника');
        $io->writeln($answer);
        $io->newLine();
        $io->comment(sprintf(
            'состояние: %s · принципов из базы: %d',
            $snap !== null ? 'снимок #' . $snap->getId() : 'нет снимка',
            count($chunks),
        ));

        return Command::SUCCESS;
    }
}
