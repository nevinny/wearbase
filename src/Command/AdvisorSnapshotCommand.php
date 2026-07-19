<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StateSnapshot;
use App\Repository\StateSnapshotRepository;
use App\Service\Advisor\SignalCollector;
use App\Service\Report\CardConversionCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Шаг 1 цикла советника (docs/advisor.md): снять текущий KPI-вектор через SignalCollector,
 * вычислить пофилдовую дельту к предыдущему снимку (ДО сохранения нового) и записать
 * StateSnapshot. Снапшоты — история, идемпотентность не нужна.
 *
 *   php bin/console app:advisor:snapshot            # снять + сохранить
 *   php bin/console app:advisor:snapshot --dry-run  # только показать metrics+delta
 */
#[AsCommand(name: 'app:advisor:snapshot', description: 'Снять KPI-вектор проекта → StateSnapshot + дельта (шаг 1 советника)')]
class AdvisorSnapshotCommand extends Command
{
    public function __construct(
        private readonly SignalCollector $collector,
        private readonly StateSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
        private readonly CardConversionCalculator $cardConversion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать metrics+delta, не сохранять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $metrics = $this->collector->collect();
        if ($metrics === []) {
            $io->error('SignalCollector не собрал ни одной метрики (все источники недоступны?).');
            return Command::FAILURE;
        }

        // Conversion-loop KPI (не из SignalCollector — сквозная конверсия карточки,
        // gsc_page_stats локально + /go/ клики с прода по агент-API). Best-effort:
        // недоступно (нет прод-URL, синк не залил GSC) → метрика просто не добавляется.
        $conv = $this->cardConversion->compute();
        if ($conv['available']) {
            $metrics['card_conversion_weekly'] = $conv['this_week']['rate'];
        }

        $prev  = $this->snapshots->findLatest();
        $delta = $this->computeDelta($metrics, $prev?->getMetrics());

        // Таблица metrics + delta для наглядности
        $rows = [];
        foreach ($metrics as $k => $v) {
            $d = $delta[$k] ?? null;
            $rows[] = [$k, $v, $d === null ? '—' : sprintf('%+d', $d)];
        }
        ksort($rows);
        $io->table(['metric', 'value', 'Δ'], $rows);

        if ($delta === null) {
            $io->note('Предыдущего снимка нет — это первый, дельта не считается.');
        }

        if ($dryRun) {
            $io->success(sprintf('dry-run: собрано %d метрик, не сохранено.', count($metrics)));
            return Command::SUCCESS;
        }

        $snap = (new StateSnapshot())
            ->setMetrics($metrics)
            ->setDelta($delta);
        $this->em->persist($snap);
        $this->em->flush();

        $io->success(sprintf('Снимок #%d сохранён (%d метрик).', $snap->getId(), count($metrics)));

        return Command::SUCCESS;
    }

    /**
     * Пофилдовая дельта now vs prev по объединению ключей (отсутствующее = 0).
     * @param array<string, int|float> $now
     * @param array<string, int|float>|null $prev
     * @return array<string, int|float>|null null — если предыдущего снимка нет
     */
    private function computeDelta(array $now, ?array $prev): ?array
    {
        if ($prev === null) {
            return null;
        }
        $delta = [];
        foreach (array_keys($now + $prev) as $k) {
            $delta[$k] = ($now[$k] ?? 0) - ($prev[$k] ?? 0);
        }

        return $delta;
    }
}
