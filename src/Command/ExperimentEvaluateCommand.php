<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MechanicExperiment;
use App\Notification\AdminNotifier;
use App\Repository\MechanicExperimentRepository;
use App\Service\Experiment\CohortMetricProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Петля экспериментов над механиками — шаг «замерить» (docs/mechanic_experiments.md).
 *
 * Для running-экспериментов с истёкшим окном (ends_at ≤ now) считает метрику когорт A/B за окно
 * ПОСЛЕ и сравнивает с baseline (снят при --start) методом diff-in-diff:
 *   DiD = (A_after − A_before) − (B_after − B_before)
 * B (контроль/holdout) вычитает сезонность/общий тренд. Вердикт adopt/rollback/inconclusive по
 * порогам (пол выборки + относительный сдвиг), пишет result_json, status=measured, шлёт сводку в TG.
 *
 * Саму правку механики adopt/rollback вносит владелец руками; здесь — только фиксация решения:
 *   --adopt=<id>    measured→adopted
 *   --rollback=<id> measured→rolled_back
 *
 *   php bin/console app:experiment:evaluate            # оценить due (крон ежедневно)
 *   php bin/console app:experiment:evaluate --dry-run  # показать вердикты, не сохранять
 *   php bin/console app:experiment:evaluate --adopt=7  # зафиксировать «оставили»
 */
#[AsCommand(name: 'app:experiment:evaluate', description: 'Замер экспериментов над механиками (diff-in-diff) → adopt/rollback')]
class ExperimentEvaluateCommand extends Command
{
    /** Ниже стольких показов суммарно (A+B, after) судить нельзя — inconclusive. */
    private const MIN_SAMPLE_IMPR = 30;
    /** Относительный порог DiD к baseline A (шум). */
    private const DELTA_REL = 0.1;
    /** Нет свежих GSC-данных за столько дней → синк сломан, не судим по нулям. */
    private const GSC_STALE_DAYS = 5;

    public function __construct(
        private readonly MechanicExperimentRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly CohortMetricProbe $probe,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать вердикты, ничего не менять');
        $this->addOption('adopt', null, InputOption::VALUE_REQUIRED, 'measured→adopted по id');
        $this->addOption('rollback', null, InputOption::VALUE_REQUIRED, 'measured→rolled_back по id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('adopt') !== null) {
            return $this->finalize($io, (int) $input->getOption('adopt'), MechanicExperiment::STATUS_ADOPTED);
        }
        if ($input->getOption('rollback') !== null) {
            return $this->finalize($io, (int) $input->getOption('rollback'), MechanicExperiment::STATUS_ROLLED_BACK);
        }

        return $this->evaluate($io, (bool) $input->getOption('dry-run'));
    }

    private function evaluate(SymfonyStyle $io, bool $dryRun): int
    {
        // Гейт свежести данных: пустая/протухшая/отсутствующая gsc_page_stats (в т.ч. env пуст
        // в test, где таблица не провижинится) → graceful no-op, не судим по нулям (ложный rollback).
        try {
            $lastDay = $this->db->fetchOne('SELECT MAX(day) FROM gsc_page_stats');
        } catch (\Throwable $e) {
            $io->warning('gsc_page_stats недоступна (' . $e->getMessage() . ') — оценка пропущена (no-op).');
            return Command::SUCCESS;
        }
        $staleBefore = (new \DateTime('-' . self::GSC_STALE_DAYS . ' days'))->format('Y-m-d');
        if ($lastDay === null || $lastDay < $staleBefore) {
            $io->warning(sprintf('GSC-данные пусты/устарели (%s) — оценка пропущена (no-op).', $lastDay ?: 'нет данных'));
            return Command::SUCCESS;
        }

        $due = $this->repo->findRunningDue(new \DateTime());
        if ($due === []) {
            $io->success('Нет running-экспериментов с истёкшим окном.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Замер экспериментов: %d', count($due)));

        foreach ($due as $exp) {
            $result = $this->measure($exp);

            $io->writeln(sprintf(
                '  #%d %s: DiD %+.4f → %s (A %.4f→%.4f · B %.4f→%.4f)',
                $exp->getId(), $exp->getCode(), $result['did'], strtoupper($result['recommendation']),
                $result['baseline']['a']['value'], $result['after']['a']['value'],
                $result['baseline']['b']['value'], $result['after']['b']['value'],
            ));

            if ($dryRun) {
                continue;
            }

            $exp->setResultJson($result)->setStatus(MechanicExperiment::STATUS_MEASURED);
            $this->em->flush();
            $this->notify($this->summaryMessage($exp, $result));
        }

        $io->success('Замер завершён.');

        return Command::SUCCESS;
    }

    /**
     * @return array{period_days:int,metric:string,baseline:array,after:array,did:float,rel:float,recommendation:string}
     */
    private function measure(MechanicExperiment $exp): array
    {
        $prev   = $exp->getResultJson() ?? [];
        $ends   = $exp->getEndsAt() ?? new \DateTime();
        $before = (clone $ends)->modify('-' . $exp->getPeriodDays() . ' days');

        // Baseline: берём снятый при старте; если его нет (страховка) — считаем за окно до start.
        $baseline = $prev['baseline'] ?? [
            'a' => $this->probe->measure($exp->getCohortA(), $exp->getMetric(), (clone($exp->getStartedAt() ?? $before))->modify('-' . $exp->getPeriodDays() . ' days'), $exp->getStartedAt() ?? $before),
            'b' => $this->probe->measure($exp->getCohortB(), $exp->getMetric(), (clone($exp->getStartedAt() ?? $before))->modify('-' . $exp->getPeriodDays() . ' days'), $exp->getStartedAt() ?? $before),
        ];

        $afterA = $this->probe->measure($exp->getCohortA(), $exp->getMetric(), $before, $ends);
        $afterB = $this->probe->measure($exp->getCohortB(), $exp->getMetric(), $before, $ends);

        $did = ($afterA['value'] - $baseline['a']['value']) - ($afterB['value'] - $baseline['b']['value']);

        $baseAVal = (float) $baseline['a']['value'];
        $rel = $baseAVal > 0 ? $did / $baseAVal : ($did > 0 ? 1.0 : ($did < 0 ? -1.0 : 0.0));

        $sample = $afterA['impr'] + $afterB['impr'];
        $recommendation = match (true) {
            $sample < self::MIN_SAMPLE_IMPR => 'inconclusive',
            $rel >=  self::DELTA_REL        => 'adopt',
            $rel <= -self::DELTA_REL        => 'rollback',
            default                          => 'inconclusive',
        };

        return [
            'period_days'    => $exp->getPeriodDays(),
            'metric'         => $exp->getMetric(),
            'baseline'       => $baseline,
            'after'          => ['a' => $afterA, 'b' => $afterB, 'measured_at' => (new \DateTime())->format('Y-m-d H:i:s')],
            'did'            => round($did, 6),
            'rel'            => round($rel, 4),
            'recommendation' => $recommendation,
        ];
    }

    private function finalize(SymfonyStyle $io, int $id, string $status): int
    {
        $exp = $this->repo->find($id);
        if ($exp === null) {
            $io->error("Эксперимент #{$id} не найден.");
            return Command::FAILURE;
        }
        if ($exp->getStatus() !== MechanicExperiment::STATUS_MEASURED) {
            $io->error(sprintf('Эксперимент #%d в статусе «%s» — зафиксировать можно только measured.', $id, $exp->getStatus()));
            return Command::FAILURE;
        }

        $exp->setStatus($status);
        $this->em->flush();

        $label = $status === MechanicExperiment::STATUS_ADOPTED ? 'принят (оставили правку)' : 'откачен (правку убрать)';
        $io->success(sprintf('Эксперимент #%d: %s.', $id, $label));
        $this->notify(sprintf('🧪 Эксперимент #%d — %s.', $id, $label));

        return Command::SUCCESS;
    }

    /** @param array{did:float,rel:float,recommendation:string,metric:string} $r */
    private function summaryMessage(MechanicExperiment $exp, array $r): string
    {
        $verdictLabel = match ($r['recommendation']) {
            'adopt'    => '✅ Рекомендую ОСТАВИТЬ (adopt)',
            'rollback' => '↩️ Рекомендую ОТКАТИТЬ (rollback)',
            default    => '🤷 Неубедительно (inconclusive)',
        };
        $cmd = $r['recommendation'] === 'rollback'
            ? sprintf('php bin/console app:experiment:evaluate --rollback=%d', $exp->getId())
            : sprintf('php bin/console app:experiment:evaluate --adopt=%d', $exp->getId());

        return sprintf(
            "🧪 <b>Эксперимент #%d измерен</b>\n%s\n\n<b>%s</b>\nМетрика: %s · DiD %+.4f (%.0f%% к baseline)\n\nЗафиксировать:\n<code>%s</code>",
            $exp->getId(),
            htmlspecialchars($exp->getHypothesis()),
            $verdictLabel,
            $r['metric'],
            $r['did'],
            $r['rel'] * 100,
            $cmd,
        );
    }

    private function notify(string $html): void
    {
        if ($this->notifier->isEnabled()) {
            $this->notifier->send($html);
        }
    }
}
