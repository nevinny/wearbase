<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceUrl;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandSourceUrlRepository;
use App\Service\BrandSourceFinder;
use App\Service\Discovery\DiscoveredUrl;
use App\Service\SearxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RAG-этап 0 (discovery, лёгкий): через SearXNG/DB-ссылки находит URL-кандидаты бренда
 * (БЕЗ скачивания страниц) и кладёт их в очередь brand_source_url с дедупом по url_hash
 * и cap'ами по source_type. Выставляет BrandRagPipeline.has_own_site (provisional) +
 * discovered_at. НЕ трогает pipeline.status — дренаж очереди делает app:brand:fetch.
 *
 * Безопасен для фона: EM-reset на DB-ошибке, шардинг --shard/--total для параллельных
 * процессов, usleep между брендами (вежливость к SearXNG).
 *
 *   php bin/console app:brand:discover --id=42 --dry-run
 *   php bin/console app:brand:discover 200 --total=4 --shard=0 --quiet >> var/log/discover0.log 2>&1 &
 */
#[AsCommand(
    name: 'app:brand:discover',
    description: 'RAG: discovery источников бренда (SearXNG) → brand_source_url',
)]
class DiscoverBrandSourcesCommand extends Command
{
    private const SLEEP_BETWEEN_MS = 1000;  // вежливость к SearXNG между брендами
    private const DEFAULT_MAX      = 50;    // максимум кандидатов на бренд (передаётся в discoverTiered)

    /** Cap по source_type: максимум URL данного типа в очереди у бренда (суммарно по запускам). */
    private const CAPS = [
        BrandSourceUrl::TYPE_OWN_SITE       => 2,
        BrandSourceUrl::TYPE_MARKETPLACE    => 5,
        BrandSourceUrl::TYPE_CATALOG        => 6,
        BrandSourceUrl::TYPE_ARTICLE_REVIEW => 5,
        BrandSourceUrl::TYPE_SOCIAL         => 6,
        BrandSourceUrl::TYPE_MENTION        => 6,
    ];

    /** Подряд брендов, у кого ОБА источника поиска (Yandex API + SearXNG) легли → стоп прогона.
     *  Падение только SearXNG при живом Yandex НЕ считается (Yandex — первичный источник). */
    private const SEARX_DOWN_ABORT = 3;

    private int $discovered = 0;   // брендов с ≥1 новым URL в очереди
    private int $empty      = 0;   // брендов без новых URL (всё уже было / ничего не нашли)
    private int $enqueued   = 0;   // всего новых URL положено в очередь
    private int $withSite   = 0;   // брендов с has_own_site=true
    private int $failed     = 0;
    private int $searxDownStreak = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry       $managerRegistry,
        private readonly BrandSourceFinder     $finder,
        private readonly BrandSourceUrlRepository $urlRepo,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Один бренд по ID')
            ->addOption('max',     null, InputOption::VALUE_REQUIRED, 'Максимум кандидатов на бренд', (string) self::DEFAULT_MAX)
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', '0')
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять, показать найденное')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $max     = max(1, (int) $input->getOption('max'));
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));
        $dryRun  = (bool) $input->getOption('dry-run');

        $io->title('RAG · discovery источников брендов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $max, $dryRun);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $this->findForDiscover($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на discovery.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к discovery: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $max, $dryRun);
            }
            // Circuit breaker: N брендов подряд без поиска — дальше идти бессмысленно,
            // прогон только жжёт время. Бренды не помечены, повторный запуск доберёт.
            if ($this->searxDownStreak >= self::SEARX_DOWN_ABORT) {
                $io->progressFinish();
                $io->error(sprintf('Поиск недоступен — Yandex API и SearXNG оба легли (%d брендов подряд) — стоп. Перезапусти, когда поиск оживёт.', $this->searxDownStreak));
                $this->printResults($io);
                return Command::FAILURE;
            }
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Активные бренды, для которых discovery ещё не отрабатывал (нет pipeline-строки
     * или discovered_at IS NULL). Шард MOD(b.id, total) = shard. Инлайн — нельзя трогать репо.
     *
     * @return Brand[]
     */
    private function findForDiscover(int $limit, int $shard, int $total): array
    {
        $qb = $this->em->getRepository(Brand::class)->createQueryBuilder('b')
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            // new = скрыт до дрип-публикации, но конвейер его готовит
            ->where('b.status IN (:statuses)')
            ->andWhere('p.id IS NULL OR p.discoveredAt IS NULL')
            ->setParameter('statuses', [Statuses::Active, Statuses::New]);

        if ($total > 1) {
            $qb->andWhere('MOD(b.id, :total) = :shard')
                ->setParameter('total', $total)
                ->setParameter('shard', $shard);
        }

        return $qb->orderBy('b.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, int $max, bool $dryRun): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $candidates = $this->finder->discoverTiered($brand, $max);

            $hasOwnSite = false;
            foreach ($candidates as $d) {
                if ($d->sourceType === BrandSourceUrl::TYPE_OWN_SITE && $d->live) {
                    $hasOwnSite = true;
                    break;
                }
            }

            // Cap'ы по типу: оставшиеся слоты с учётом уже лежащих в очереди строк.
            // countByBrandType — SQL COUNT, не видит несфлашенные persist() → считаем один раз
            // на тип и декрементируем в памяти, иначе в рамках одного запуска перельём cap.
            $remaining = [];
            $newUrls = 0;

            foreach ($candidates as $d) {
                $url  = mb_substr(rtrim((string) $d->url, '/'), 0, 1024);
                if ($url === '') {
                    continue;
                }
                $hash = BrandSourceUrl::normalizeHash($url);

                // Дедуп: URL уже в очереди у этого бренда.
                if ($this->urlRepo->findOneByBrandUrlHash($brand, $hash) !== null) {
                    continue;
                }

                $type = $d->sourceType;
                $cap  = self::CAPS[$type] ?? self::CAPS[BrandSourceUrl::TYPE_MENTION];
                if (!isset($remaining[$type])) {
                    $remaining[$type] = max(0, $cap - $this->urlRepo->countByBrandType($brand, $type));
                }
                if ($remaining[$type] <= 0) {
                    continue;
                }
                $remaining[$type]--;

                $io->text(sprintf('     + %s [%s t%d %.2f]', $this->shortUrl($url), $type, $d->tier, $d->relevanceScore));

                if (!$dryRun) {
                    $row = (new BrandSourceUrl())
                        ->setBrand($brand)
                        ->setUrl($url)
                        ->setSourceType($type)
                        ->setTier($d->tier)
                        ->setRelevanceScore($d->relevanceScore)
                        ->setStatus(BrandSourceUrl::STATUS_PENDING);
                    $this->em->persist($row);
                }
                $newUrls++;
            }

            $io->text(sprintf('  → %s: +%d URL, own_site=%s', $name, $newUrls, $hasOwnSite ? 'да' : 'нет'));

            $this->enqueued += $newUrls;
            $this->discovered += $newUrls > 0 ? 1 : 0;
            $this->empty += $newUrls === 0 ? 1 : 0;
            $this->withSite += $hasOwnSite ? 1 : 0;

            if (!$dryRun) {
                $pipeline = $this->pipeline($brand);
                $pipeline->setHasOwnSite($hasOwnSite)
                    ->setDiscoveredAt(new \DateTime());
                $this->em->flush();
                $this->em->clear();
            }
            $this->searxDownStreak = 0;
        } catch (SearxUnavailableException $e) {
            // Поиск лежит (движки suspended/CAPTCHA) — НЕ помечаем discovered:
            // иначе бренд сгорает с пустыми тирами и больше не переобходится.
            $io->warning(sprintf('    SearXNG лежит, «%s» пропущен (не помечен): %s', $name, $e->getMessage()));
            $this->failed++;
            $this->searxDownStreak++;
            $this->recoverEm();
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recoverEm();
        }
    }

    /** Гарантирует pipeline-строку для уже управляемого бренда (status не трогаем). */
    private function pipeline(Brand $brand): BrandRagPipeline
    {
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        return $repo->getOrCreate($brand);
    }

    /** Восстановление EM после DB-ошибки, чтобы батч продолжился. */
    private function recoverEm(): void
    {
        if (!$this->em->isOpen()) {
            $this->em = $this->managerRegistry->resetManager();
        } else {
            $this->em->clear();
        }
    }

    private function shortUrl(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? $url);
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Брендов с новыми URL',  $this->discovered],
            ['Брендов без новых URL', $this->empty],
            ['URL в очередь',         $this->enqueued],
            ['С own_site',            $this->withSite],
            ['Ошибок',                $this->failed],
        ]);
    }
}
