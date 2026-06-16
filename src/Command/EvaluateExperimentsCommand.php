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
    private const RE_MEASURE_DAYS = 14;   // not_indexed: через сколько перепроверить (ждём index-ping)
    private const MAX_INDEX_WAIT_DAYS = 60; // дольше не ждём индексацию → терминальный not_indexed
    private const GSC_STALE_DAYS  = 5;    // нет свежих GSC-данных за столько дней → не судим (синк сломан)
    private const LOSS_CONFIRM_WINDOWS = 2; // антифлаппинг: реген только после стольких окон loss подряд

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

        // ГАРД свежести GSC: если gsc:sync молча сломался (креды/квота), данные устаревают,
        // gscSnapshot вернёт нули → ВСЁ уйдёт в not_indexed (и теперь в вечный re-measure),
        // маскируя поломку. Не судим по протухшим данным. GSC сам лагает ~2-3 дня, поэтому
        // порог GSC_STALE_DAYS с запасом — ловим именно многодневный обрыв синка.
        $lastGscDay = $this->db->fetchOne('SELECT MAX(day) FROM gsc_page_stats');
        $staleBefore = (new \DateTime('-' . self::GSC_STALE_DAYS . ' days'))->format('Y-m-d');
        if ($lastGscDay === null || $lastGscDay < $staleBefore) {
            $io->error(sprintf(
                'GSC-данные устарели (последний день: %s, порог: %s). Оценка ПРОПУЩЕНА — иначе ложный not_indexed по нулям. Проверь app:gsc:sync.',
                $lastGscDay ?: 'нет данных', $staleBefore,
            ));
            return Command::FAILURE;
        }

        $due = $this->revisions->findDueForEvaluation(new \DateTime(), $limit);
        $io->title(sprintf('Closed-loop: ревизий к оценке %d', count($due)));
        if ($due === []) {
            $io->success('Нет экспериментов с истёкшим окном.');
            return Command::SUCCESS;
        }

        $tally = ['win' => 0, 'loss' => 0, 'neutral' => 0, 'not_indexed' => 0, 'regen' => 0, 'rollback' => 0, 'remeasure' => 0, 'loss_tentative' => 0];

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
                // Антифлаппинг: реагируем только на ПОДТВЕРЖДЁННЫЙ loss (≥2 окна подряд).
                // Первый loss на низкочастотке часто шум → не дёргаем контент, ждём ещё окно.
                if ($rev->getLossStreak() + 1 < self::LOSS_CONFIRM_WINDOWS) {
                    $action = 'loss_tentative';
                    $tally['loss_tentative']++;
                } elseif ($rev->getAttempt() < self::MAX_ATTEMPT && $this->hasGroundedCorpus($brandId)) {
                    $action = 'regen';
                    $tally[$action]++;
                } else {
                    $action = 'rollback';
                    $tally[$action]++;
                }
            } elseif ($verdict === BrandContentRevision::VERDICT_NOT_INDEXED) {
                // Контент не виноват — Google не дал шанс. Не финализируем терминально:
                // даём index-ping'у время и ПЕРЕОТКРЫВАЕМ окно, чтобы оценить контент, когда
                // страница попадёт в индекс. Сдаёмся (терминальный not_indexed) только если
                // ждём индексацию дольше MAX_INDEX_WAIT_DAYS.
                $ageDays = (new \DateTime())->diff($rev->getCreatedAt())->days;
                if ($ageDays < self::MAX_INDEX_WAIT_DAYS) {
                    $action = 'remeasure';
                    $tally['remeasure']++;
                }
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

            $rev->setGscImprAfter($impr)->setGscClicksAfter($clicks)->setGscIndexedAfter($indexed);

            if ($action === 'remeasure' || $action === 'loss_tentative') {
                // verdict ОСТАЁТСЯ pending → ревизия вернётся в оценку после нового окна.
                //  - remeasure (not_indexed): ждём индексацию (index-ping);
                //  - loss_tentative: первый loss, ждём подтверждения трендом ещё одним окном.
                if ($action === 'loss_tentative') {
                    $rev->setLossStreak($rev->getLossStreak() + 1);
                }
                $rev->setMeasureAfter((new \DateTime())->modify('+' . self::RE_MEASURE_DAYS . ' days'));
                $this->em->flush();
                $this->em->clear();
                continue;
            }

            $rev->setVerdict($verdict);

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
