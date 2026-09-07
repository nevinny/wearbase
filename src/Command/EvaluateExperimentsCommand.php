<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandContentRevision;
use App\Entity\BrandRagPipeline;
use App\Entity\CityHubRevision;
use App\Repository\BrandContentRevisionRepository;
use App\Repository\CityHubRevisionRepository;
use App\Service\BrandContentVersioner;
use App\Service\ClosedLoopJudge;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closed-loop тик: оценивает ревизии-эксперименты, чьё окно замера истекло, сверяя метрику
 * variant vs baseline. С 2026-07-03 источник правды — Яндекс (in_search + матчированные
 * запросы) + GSC вторично (см. BrandContentVersioner::gscSnapshot); покрытие Google
 * заморожено с ~12.06. Дерево решений (см. docs/rag_pipeline.md §10):
 *
 *   не в индексе → not_indexed (НЕ откат: поиск не дал шанс)
 *   в индексе, показов < MIN_SAMPLE → win, если вошла в индекс после ревизии, иначе not_indexed
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
        private readonly CityHubRevisionRepository $cityRevisions,
        private readonly ClosedLoopJudge $judge,
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

        // ГАРД свежести ОБОИХ источников (Яндекс первичный, GSC вторичный): если какой-то
        // синк молча сломался (креды/квота), его часть метрики уйдёт в ноль → ложные
        // not_indexed/loss, маскирующие поломку. Не судим по протухшим данным. GSC сам
        // лагает ~2-3 дня, поэтому порог GSC_STALE_DAYS с запасом — ловим обрыв синка.
        $staleBefore = (new \DateTime('-' . self::GSC_STALE_DAYS . ' days'))->format('Y-m-d');
        $lastGscDay = $this->db->fetchOne('SELECT MAX(day) FROM gsc_page_stats');
        if ($lastGscDay === null || $lastGscDay < $staleBefore) {
            $io->error(sprintf(
                'GSC-данные устарели (последний день: %s, порог: %s). Оценка ПРОПУЩЕНА — иначе ложный not_indexed по нулям. Проверь app:gsc:sync.',
                $lastGscDay ?: 'нет данных', $staleBefore,
            ));
            return Command::FAILURE;
        }
        $lastYaCheck = $this->db->fetchOne('SELECT MAX(last_checked_at) FROM yandex_index_status');
        if ($lastYaCheck === null || substr((string) $lastYaCheck, 0, 10) < $staleBefore) {
            $io->error(sprintf(
                'Яндекс-данные устарели (последняя проверка: %s, порог: %s). Оценка ПРОПУЩЕНА. Проверь app:yandex:sync.',
                $lastYaCheck ?: 'нет данных', $staleBefore,
            ));
            return Command::FAILURE;
        }

        $due = $this->revisions->findDueForEvaluation(new \DateTime(), $limit);
        $io->title(sprintf('Closed-loop (бренды): ревизий к оценке %d', count($due)));
        if ($due === []) {
            $io->text('Нет экспериментов брендов с истёкшим окном.');
        }

        $tally = ['win' => 0, 'loss' => 0, 'neutral' => 0, 'not_indexed' => 0, 'regen' => 0, 'rollback' => 0, 'remeasure' => 0, 'loss_tentative' => 0];

        // Только id: em->clear() в конце итерации отцепляет ВСЕ заранее выбранные сущности —
        // мутации детачнутых ревизий flush молча игнорирует (до 2026-07-03 из-за этого
        // персистился только ПЕРВЫЙ вердикт прогона). Перезагружаем каждую по id.
        $dueIds = array_map(static fn(BrandContentRevision $r) => (int) $r->getId(), $due);
        $this->em->clear();

        foreach ($dueIds as $revId) {
            $rev = $this->em->find(BrandContentRevision::class, $revId);
            $brand = $rev?->getBrand();
            if ($rev === null || $brand === null) {
                continue;
            }
            $brandId = (int) $brand->getId();
            [$impr, $clicks, $indexed] = $this->versioner->gscSnapshot($brandId);

            $verdict = $this->judge->verdict(
                $rev->getGscImprBefore() ?? 0,
                $rev->getGscClicksBefore() ?? 0,
                $rev->getGscIndexedBefore() ?? false,
                $impr,
                $clicks,
                $indexed,
            );
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

        // Городская ветка: тот же крон, тот же гвард свежести (уже проверен выше),
        // тот же судья (ClosedLoopJudge) — второй цикл в ОДНОЙ команде, не новая.
        $this->evaluateCityHubs($io, $dryRun, $limit);

        $io->success('Оценка завершена.');

        return Command::SUCCESS;
    }

    /**
     * Closed-loop для городских хабов (см. CityHubRevision/CityHubRevisionRepository).
     * Проще бренд-ветки: без антифлаппинга/регена/remeasure — тексты хаба короткие
     * и переписываются человеком-куратором заново, а не автоматическим ретраем RAG.
     * win/neutral/not_indexed — просто фиксируем вердикт. loss → откат к предыдущей
     * ревизии; если предыдущей нет (первая генерация) — откат = выключить хаб
     * (CityHub::isActive=false), страница возвращается на формульный fallback.
     * Физического DELETE нет ни в каком случае.
     */
    private function evaluateCityHubs(SymfonyStyle $io, bool $dryRun, int $limit): void
    {
        $due = $this->cityRevisions->findDueForEvaluation(new \DateTime(), $limit);
        $io->title(sprintf('Closed-loop (города): ревизий к оценке %d', count($due)));
        if ($due === []) {
            $io->text('Нет экспериментов городских хабов с истёкшим окном.');
            return;
        }

        $tally = ['win' => 0, 'loss' => 0, 'neutral' => 0, 'not_indexed' => 0, 'rollback' => 0, 'deactivated' => 0];

        $dueIds = array_map(static fn(CityHubRevision $r) => (int) $r->getId(), $due);
        $this->em->clear();

        foreach ($dueIds as $revId) {
            $rev = $this->em->find(CityHubRevision::class, $revId);
            $hub = $rev?->getHub();
            if ($rev === null || $hub === null) {
                continue;
            }
            [$impr, $clicks, $indexed] = $this->cityRevisions->citySnapshot($rev->getSlug());

            $verdict = $this->judge->verdict(
                $rev->getGscImprBefore() ?? 0,
                $rev->getGscClicksBefore() ?? 0,
                $rev->getGscIndexedBefore() ?? false,
                $impr,
                $clicks,
                $indexed,
            );
            $tally[$verdict]++;

            $io->writeln(sprintf(
                '  #%d %s: %s · impr %d→%d, clk %d→%d, idx %s',
                $rev->getId(), $hub->getSlug(), strtoupper($verdict),
                $rev->getGscImprBefore() ?? 0, $impr,
                $rev->getGscClicksBefore() ?? 0, $clicks,
                $indexed ? 'да' : 'нет',
            ));

            if ($dryRun) {
                continue;
            }

            $rev->setGscImprAfter($impr)->setGscClicksAfter($clicks)->setVerdict($verdict);

            if ($verdict === BrandContentRevision::VERDICT_LOSS) {
                $prev = $this->cityRevisions->findPrevious($rev);
                if ($prev === null || trim((string) $prev->getIntro()) === '') {
                    // Истории до этого эксперимента нет (первая генерация, ровно случай
                    // 7 новых городов) — откатывать некуда, просто выключаем хаб.
                    $hub->setIsActive(false);
                    $rev->setNote('closed-loop: loss, прошлой ревизии нет → хаб выключен (fallback)');
                    $tally['deactivated']++;
                } else {
                    $rollback = (new CityHubRevision())
                        ->setHub($hub)
                        ->setSlug($rev->getSlug())
                        ->setH1($prev->getH1())
                        ->setMetaTitle($prev->getMetaTitle())
                        ->setMetaDescription($prev->getMetaDescription())
                        ->setIntro($prev->getIntro())
                        ->setFaq($prev->getFaq())
                        ->setSource(CityHubRevision::SOURCE_ROLLBACK)
                        ->setQaOverall($prev->getQaOverall())
                        ->setActive(true)
                        ->setPrevRevisionId($prev->getId())
                        ->setVerdict(BrandContentRevision::VERDICT_WIN) // откат к известному рабочему — не эксперимент
                        ->setNote('closed-loop: loss → откат к ревизии #' . $prev->getId());
                    $this->em->persist($rollback);
                    $rev->setActive(false);

                    $hub->setH1($prev->getH1())
                        ->setMetaTitle($prev->getMetaTitle())
                        ->setMetaDescription($prev->getMetaDescription())
                        ->setIntro($prev->getIntro())
                        ->setFaq($prev->getFaq());
                    $tally['rollback']++;
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        foreach ($tally as $k => $v) {
            if ($v > 0) {
                $io->text(sprintf('города · %s: %d', $k, $v));
            }
        }
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
