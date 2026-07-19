<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MechanicExperiment;
use App\Notification\AdminNotifier;
use App\Repository\MechanicExperimentRepository;
use App\Service\Experiment\CohortMetricProbe;
use App\Service\Experiment\MechanicExperimentBacklog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Петля экспериментов над механиками — шаг «предложить» + «запустить» (docs/mechanic_experiments.md).
 *
 * Без опций (крон, понедельник): берёт бэклог гипотез (MechanicExperimentBacklog), отбрасывает
 * уже заведённые (по code), выбирает ОДНУ с максимальным ICE, заводит строку status=proposed и
 * шлёт владельцу в TG человек-гейт: гипотезу + команду запуска. Тап-механики нет (вебхук на проде
 * не видит строк Mac-БД, тот же нюанс что в docs/aio_remediation.md) → честный MVP: TG-сообщение
 * с командой, запуск руками ПОСЛЕ внесения правки механики.
 *
 * --start=<id>: перевести proposed→running, снять baseline когорт A/B, поставить окно замера.
 *
 *   php bin/console app:experiment:propose             # предложить одну (крон пн)
 *   php bin/console app:experiment:propose --dry-run    # показать выбор, не сохранять/не слать
 *   php bin/console app:experiment:propose --start=7     # запустить эксперимент #7 (руками)
 */
#[AsCommand(name: 'app:experiment:propose', description: 'Предложить эксперимент над механикой (ICE, человек-гейт TG) / запустить по --start')]
class ExperimentProposeCommand extends Command
{
    public function __construct(
        private readonly MechanicExperimentRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly AdminNotifier $notifier,
        private readonly CohortMetricProbe $probe,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать выбор, не сохранять и не слать');
        $this->addOption('start', null, InputOption::VALUE_REQUIRED, 'Запустить proposed-эксперимент по id (proposed→running)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('start') !== null) {
            return $this->start($io, (int) $input->getOption('start'));
        }

        return $this->propose($io, (bool) $input->getOption('dry-run'));
    }

    private function propose(SymfonyStyle $io, bool $dryRun): int
    {
        $existing = $this->repo->existingCodes();
        $candidates = array_filter(
            MechanicExperimentBacklog::all(),
            static fn(array $h) => !in_array($h['code'], $existing, true),
        );

        if ($candidates === []) {
            $io->success('Весь бэклог гипотез механик уже предложен — новых нет.');
            return Command::SUCCESS;
        }

        // ICE = impact*confidence*ease. Максимум — предлагаем первым.
        usort($candidates, static fn(array $a, array $b) => self::ice($b) <=> self::ice($a));
        $pick = $candidates[0];
        $ice  = self::ice($pick);

        $io->section(sprintf('Выбор по ICE: %s (ICE %d)', $pick['code'], $ice));
        $io->text($pick['hypothesis']);
        $io->text(sprintf('target: %s · метрика: %s · I%d·C%d·E%d', $pick['target'], $pick['metric'], $pick['impact'], $pick['confidence'], $pick['ease']));

        if ($dryRun) {
            $io->note('dry-run: не сохранено и не отправлено.');
            return Command::SUCCESS;
        }

        $exp = (new MechanicExperiment())
            ->setCode($pick['code'])
            ->setHypothesis($pick['hypothesis'])
            ->setTarget($pick['target'])
            ->setMetric($pick['metric'])
            ->setCohortA($pick['cohort_a'])
            ->setCohortB($pick['cohort_b'])
            ->setImpact($pick['impact'])
            ->setConfidence($pick['confidence'])
            ->setEase($pick['ease'])
            ->setIceScore($ice)
            ->setPeriodDays($pick['period_days']);
        $this->em->persist($exp);
        $this->em->flush();

        $this->notify($this->proposeMessage($exp));
        $io->success(sprintf('Эксперимент #%d (%s) предложен, status=proposed.', $exp->getId(), $exp->getCode()));

        return Command::SUCCESS;
    }

    private function start(SymfonyStyle $io, int $id): int
    {
        $exp = $this->repo->find($id);
        if ($exp === null) {
            $io->error("Эксперимент #{$id} не найден.");
            return Command::FAILURE;
        }
        if ($exp->getStatus() !== MechanicExperiment::STATUS_PROPOSED) {
            $io->error(sprintf('Эксперимент #%d в статусе «%s» — запустить можно только proposed.', $id, $exp->getStatus()));
            return Command::FAILURE;
        }

        $now   = new \DateTime();
        $ends  = (clone $now)->modify('+' . $exp->getPeriodDays() . ' days');
        $before = (clone $now)->modify('-' . $exp->getPeriodDays() . ' days');

        // Baseline: метрика когорт A/B за окно ДО старта (равной длины окну замера).
        $baseA = $this->probe->measure($exp->getCohortA(), $exp->getMetric(), $before, $now);
        $baseB = $this->probe->measure($exp->getCohortB(), $exp->getMetric(), $before, $now);

        $exp->setStatus(MechanicExperiment::STATUS_RUNNING)
            ->setStartedAt($now)
            ->setEndsAt($ends)
            ->setResultJson([
                'period_days' => $exp->getPeriodDays(),
                'metric'      => $exp->getMetric(),
                'baseline'    => ['a' => $baseA, 'b' => $baseB, 'measured_at' => $now->format('Y-m-d H:i:s')],
            ]);
        $this->em->flush();

        $io->success(sprintf(
            'Эксперимент #%d запущен. Baseline A=%.4f B=%.4f. Замер после %s.',
            $id, $baseA['value'], $baseB['value'], $ends->format('d.m.Y'),
        ));
        $this->notify(sprintf(
            "🧪 <b>Эксперимент #%d запущен</b>\n%s\nBaseline (%s): A %.4f · B %.4f\nЗамер автоматически после %s.",
            $id, htmlspecialchars($exp->getHypothesis()), $exp->getMetric(), $baseA['value'], $baseB['value'], $ends->format('d.m.Y'),
        ));

        return Command::SUCCESS;
    }

    private function proposeMessage(MechanicExperiment $exp): string
    {
        return sprintf(
            "🧭 <b>Эксперимент над механикой — предложение (ICE %d)</b>\n"
            . "%s\n\n"
            . "<b>Где:</b> %s\n<b>Метрика:</b> %s (DiD когорт A/B)\n\n"
            . "1) Внеси правку механики (см. «Где»).\n2) Запусти замер:\n<code>php bin/console app:experiment:propose --start=%d</code>",
            $exp->getIceScore(),
            htmlspecialchars($exp->getHypothesis()),
            htmlspecialchars($exp->getTarget()),
            $exp->getMetric(),
            $exp->getId(),
        );
    }

    private function notify(string $html): void
    {
        if ($this->notifier->isEnabled()) {
            $this->notifier->send($html);
        }
    }

    /** @param array{impact:int,confidence:int,ease:int} $h */
    private static function ice(array $h): int
    {
        return $h['impact'] * $h['confidence'] * $h['ease'];
    }
}
