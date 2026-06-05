<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceUrl;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandSourceUrlRepository;
use App\Service\CrawlUrlFilter;
use App\Service\WebScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RAG-этап 0.5 (краул, отдельный поток между discover и fetch): для брендов с
 * собственным сайтом разворачивает sitemap/ссылки own_site в очередь как own_page
 * (≤ CAP ценных страниц) → их дренит обычный app:brand:fetch.
 *
 * Прокси НЕ нужен (сайт бренда без анти-бота). Идемпотентно: дедуп по url_hash,
 * crawl_status защищает от повторного обхода. После обрыва — финдер доберёт.
 *
 *   php bin/console app:brand:crawl --id=42 --dry-run
 *   php bin/console app:brand:crawl 30 --no-debug    # стадия сетевого демона
 */
#[AsCommand(
    name: 'app:brand:crawl',
    description: 'RAG: краул сайта бренда (sitemap own_site → own_page в очередь)',
)]
class CrawlBrandSiteCommand extends Command
{
    private const CAP_PAGES   = 30;   // ценных страниц на бренд в очередь
    private const SITEMAP_CAP = 300;  // потолок кандидатов из sitemap (до фильтра ценности)
    private const SLEEP_MS    = 500;  // вежливость к origin между брендами

    private int $crawled = 0;   // брендов развёрнуто (≥1 own_page)
    private int $skipped = 0;   // нет own_site
    private int $enqueued = 0;  // всего own_page в очередь
    private int $failed  = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry          $managerRegistry,
        private readonly WebScraperService        $scraper,
        private readonly CrawlUrlFilter           $crawlFilter,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять, показать найденное')
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда', '0')
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $dryRun  = (bool) $input->getOption('dry-run');
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('RAG · краул сайтов брендов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var \App\Repository\BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForCrawl($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на краул.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к краулу: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun);
            }
            $io->progressAdvance();
            gc_collect_cycles();
            usleep(self::SLEEP_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            // own_site бренда из очереди (discover положил TYPE_OWN_SITE)
            $ownSite = $this->urlRepo->findOneBy([
                'brand'      => $brand,
                'sourceType' => BrandSourceUrl::TYPE_OWN_SITE,
            ]);

            if ($ownSite === null) {
                $io->text("  → {$name}: нет own_site → skipped");
                $this->finish($brand, BrandRagPipeline::CRAWL_SKIPPED, $dryRun);
                $this->skipped++;
                return;
            }

            $siteUrl = $ownSite->getUrl();
            $host    = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));

            // Развёртка: sitemap + ссылки с главной → ранжируем ценность → cap
            $candidates = $this->scraper->discoverSitePages($siteUrl, self::SITEMAP_CAP);
            $valuable = [];   // приоритет 0
            $ordinary = [];   // приоритет 1
            foreach ($candidates as $url) {
                $rank = $this->crawlFilter->rank($url, $host);
                if ($rank === 0) {
                    $valuable[] = $url;
                } elseif ($rank === 1) {
                    $ordinary[] = $url;
                }
            }
            $pages = array_slice(array_merge($valuable, $ordinary), 0, self::CAP_PAGES);

            $new = 0;
            foreach ($pages as $url) {
                $url = mb_substr(rtrim($url, '/'), 0, 1024);
                $hash = BrandSourceUrl::normalizeHash($url);
                if ($this->urlRepo->findOneByBrandUrlHash($brand, $hash) !== null) {
                    continue; // уже в очереди (own_site/own_page/др.) — дедуп
                }
                if (!$dryRun) {
                    $this->em->persist((new BrandSourceUrl())
                        ->setBrand($brand)
                        ->setUrl($url)
                        ->setSourceType(BrandSourceUrl::TYPE_OWN_PAGE)
                        ->setTier(BrandSourceUrl::TIER_OWN_SITE)
                        ->setRelevanceScore(0.85)   // own-контент — высокая релевантность
                        ->setStatus(BrandSourceUrl::STATUS_PENDING));
                }
                $new++;
            }

            $io->text(sprintf('  → %s: +%d own_page (ценных %d / всего кандидатов %d)', $name, $new, count($valuable), count($candidates)));
            $this->enqueued += $new;
            $this->crawled += $new > 0 ? 1 : 0;

            // Флаг краула + флаш own_page как pending — отдельная стадия, drain-gate
            // fetch не задет (own_page легли ДО любого fetch own_site этого прохода).
            $this->finish($brand, BrandRagPipeline::CRAWL_DONE, $dryRun);
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recoverEm();
        }
    }

    private function finish(Brand $brand, string $status, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $repo->getOrCreate($brand)
            ->setCrawlStatus($status)
            ->setCrawledAt(new \DateTime());
        $this->em->flush();
        $this->em->clear();
    }

    private function recoverEm(): void
    {
        if (!$this->em->isOpen()) {
            $this->em = $this->managerRegistry->resetManager();
        } else {
            $this->em->clear();
        }
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Брендов развёрнуто',   $this->crawled],
            ['own_page в очередь',   $this->enqueued],
            ['Без own_site',         $this->skipped],
            ['Ошибок',               $this->failed],
        ]);
    }
}
