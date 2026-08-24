<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Repository\WardrobeActivationEventRepository;
use App\Service\Wardrobe\WardrobeActivationReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:wardrobe:activation-report', description: 'Privacy-safe product activation funnel for wardrobe')]
final class ActivationReportCommand extends Command
{
    public function __construct(
        private readonly WardrobeActivationEventRepository $events,
        private readonly WardrobeActivationReport $report,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Reporting window in days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
        if ($days === false) {
            $output->writeln('<error>--days must be between 1 and 366</error>');
            return Command::INVALID;
        }
        $to = new \DateTimeImmutable('tomorrow midnight');
        $result = $this->report->build($this->events->findReportRows($to->modify(sprintf('-%d days', $days)), $to));
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
