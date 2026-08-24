<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Service\Wardrobe\WardrobeIngestHealth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:wardrobe:ingest-health', description: 'Операционное состояние очереди распознавания гардероба')]
final class WardrobeIngestHealthCommand extends Command
{
    public function __construct(private readonly WardrobeIngestHealth $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести machine-readable JSON')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Вернуть ненулевой exit code при critical');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->health->snapshot();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('Wardrobe ingest health');
            $io->table(['Metric', 'Value'], array_map(
                static fn (string $key, mixed $value): array => [$key, match (true) {
                    $value === null => 'unknown',
                    is_bool($value) => $value ? 'yes' : 'no',
                    default => (string) $value,
                }],
                array_keys($snapshot),
                array_values($snapshot),
            ));
        }

        return $input->getOption('check') && $snapshot['status'] === 'critical' ? Command::FAILURE : Command::SUCCESS;
    }
}
