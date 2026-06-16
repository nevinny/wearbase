<?php

namespace App\Command;

use App\Repository\SocialChannelRepository;
use App\Service\Social\SocialPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Планирование постов на N дней вперёд по сетке рубрик (docs/marketing_instagram.md §3).
 * Создаёт social_post(planned) — их потом наполняет app:social:generate.
 */
#[AsCommand(name: 'app:social:plan', description: 'Запланировать посты на N дней по контент-сетке')]
class SocialPlanCommand extends Command
{
    public function __construct(
        private readonly SocialChannelRepository $channels,
        private readonly SocialPlanner $planner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'ID канала (по умолчанию — все включённые)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'На сколько дней вперёд', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять, только показать счёт');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');

        $channelId = $input->getOption('channel');
        $channels = $channelId !== null
            ? array_filter([$this->channels->find((int) $channelId)])
            : $this->channels->findEnabled();

        if ($channels === []) {
            $io->warning('Нет включённых каналов для планирования.');
            return Command::SUCCESS;
        }

        $total = 0;
        foreach ($channels as $channel) {
            $n = $this->planner->planAhead($channel, $days, $dryRun);
            $total += $n;
            $io->text(sprintf('%s [%s]: +%d постов', $channel->getName(), $channel->getPlatform(), $n));
        }

        $io->success(sprintf('%sЗапланировано постов: %d (на %d дн.)', $dryRun ? '[dry-run] ' : '', $total, $days));

        return Command::SUCCESS;
    }
}
