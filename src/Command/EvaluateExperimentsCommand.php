<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandContentRevision;
use App\Entity\BrandRagPipeline;
use App\Repository\BrandContentRevisionRepository;
use App\Service\BrandContentVersioner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closed-loop тик: оценивает ревизии-эксперименты, чьё окно замера истекло, сверяя GSC
 * variant vs baseline (источник правды — GSC). Дерево решений (см. docs/rag_pipeline.md §10):
 *
 *   not indexed / impr < MIN_SAMPLE → not_indexed (НЕ откат: Google не дал шанс)
 *   иначе с порогом (rel 20% + пол): loss / win / neutral
 *   loss → attempt < MAX_ATTEMPT и есть grounded-корпус → реген (флаг regen_requested_at)
 *          иначе → откат к лучшей прошлой ревизии (+ ре-доставка на прод)
 *
 *   php bin/console app:seo:evaluate-experiments            # боевой
 *   php bin/console app:seo:evaluate-experiments --dry-run  # только показать вердикты
 */
#[AsCommand(name: 'app:seo:evaluate-experiments', description: 'Closed-loop: оценить эксперименты контента по GSC → keep/откат/реген')]
class EvaluateExperimentsCommand extends Command
{
    private const MIN_SAMPLE      = 10;   // меньше показов → судить нельзя (вероятно не в индексе)
    private const DELTA_REL       = 0.2;  // относит. порог срабатывания (шум)
    private const DELTA_ABS_CLK   = 2;    // абсолютный пол по кликам
    private const DELTA_ABS_IMPR  = 10;   // абсолютный пол по показам
    private const MAX_ATTEMPT     = 3;    // после стольких попыток — откат, не реген

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly BrandContentRevisionRepository $revisions,
        private readonly BrandContentVersioner $versioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать вердикты, ничего не менять');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум ревизий за прогон', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = max(1, (int) $input->getOption('limit'));

        $due = $this->revisions->findDueForEvaluation(new \DateTime(), $limit);
        $io->title(sprintf('Closed-loop: ревизий к оценке %d', count($due)));
        if ($due === []) {
            $io->success('Нет экспериментов с истёкшим окном.');
            return Command::SUCCESS;
        }

        $tally = ['win' => 0, 'loss' => 0, 'neutral' => 0, 'not_indexed' => 0, 'regen' => 0, 'rollback' => 0];

        foreach ($due as $rev) {
            $brand = $rev->getBrand();
            if ($brand === null) {
                continue;
            }
            $brandId = (int) $brand->getId();
            [$impr, $clicks, $indexed] = $this->versioner->gscSnapshot($brandId);

            $verdict = $this->judge($rev, $impr, $clicks, $indexed);
            $tally[$verdict]++;

            $action = '';
            if ($verdict === BrandContentRevision::VERDICT_LOSS) {
                if ($rev->getAttempt() < self::MAX_ATTEMPT && $this->hasGroundedCorpus($brandId)) {
                    $action = 'regen';
                } else {
                    $action = 'rollback';
                }
                $tally[$action]++;
            }

            $io->writeln(sprintf(
                '  #%d %s: %s · impr %d→%d, clk %d→%d, idx %s%s',
                $rev->getId(), $brand->getTitle() ?? $brandId, strtoupper($verdict),
                $rev->getGscImprBefore() ?? 0, $impr,
                $rev->getGscClicksBefore() ?? 0, $clicks,
                $indexed ? 'да' : 'нет',
                $action ? " → {$action}" : '',
            ));

            if ($dryRun) {
                continue;
            }

            $rev->setGscImprAfter($impr)->setGscClicksAfter($clicks)->setGscIndexedAfter($indexed)->setVerdict($verdict);

            if ($action === 'regen') {
                $this->pipeline($brand)
                    ->setRegenRequestedAt(new \DateTime())
                    ->setPriority(max(50, $this->pipeline($brand)->getPriority()));
            } elseif ($action === 'rollback') {
                $target = $this->revisions->findRollbackTarget($brand, (int) $rev->getId());
                if ($target !== null) {
                    $this->versioner->rollback($brand, $target, 'closed-loop: loss → откат');
                    // изменили brand.* → пометить для ре-доставки на прод
                    $this->pipeline($brand)->setContentChangedAt(new \DateTime());
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        foreach ($tally as $k => $v) {
            if ($v > 0) {
                $io->text(sprintf('%s: %d', $k, $v));
            }
        }
        $io->success('Оценка завершена.');

        return Command::SUCCESS;
    }

    private function judge(BrandContentRevision $rev, int $impr, int $clicks, bool $indexed): string
    {
        // 1. Можно ли вообще судить? Не в индексе / мало показов → контент не виноват.
        if (!$indexed || $impr < self::MIN_SAMPLE) {
            return BrandContentRevision::VERDICT_NOT_INDEXED;
        }

        $imprBefore = $rev->getGscImprBefore() ?? 0;
        $clkBefore  = $rev->getGscClicksBefore() ?? 0;
        $imprThr = max(self::DELTA_ABS_IMPR, (int) round($imprBefore * self::DELTA_REL));
        $clkThr  = max(self::DELTA_ABS_CLK, (int) round($clkBefore * self::DELTA_REL));

        $clicksDropped = $clicks < $clkBefore - $clkThr;
        $imprDropped   = $impr   < $imprBefore - $imprThr;
        $imprUp        = $impr   > $imprBefore + $imprThr;
        $newlyIndexed  = $indexed && !($rev->getGscIndexedBefore() ?? false);

        if ($clicksDropped || $imprDropped) {
            return BrandContentRevision::VERDICT_LOSS;
        }
        if (!$clicksDropped && ($imprUp || $newlyIndexed)) {
            return BrandContentRevision::VERDICT_WIN;
        }

        return BrandContentRevision::VERDICT_NEUTRAL;
    }

    private function hasGroundedCorpus(int $brandId): bool
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM brand_source_document WHERE brand_id = :id AND deleted_at IS NULL',
            ['id' => $brandId],
        ) >= 3;
    }

    private function pipeline(\App\Entity\Brand $brand): BrandRagPipeline
    {
        /** @var \App\Repository\BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);

        return $repo->getOrCreate($brand);
    }
}
