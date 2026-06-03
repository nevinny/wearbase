<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandRepository;
use App\Repository\BrandSourceDocumentRepository;
use App\Service\BrandSourceFinder;
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
 * RAG-этап 1: находит источники бренда, скачивает страницы, чистит HTML→текст
 * и сохраняет в brand_source_document. Исключает wearbase.ru (UrlFilter + защитно
 * в WebScraperService). Безопасен для фона: статус в brand_rag_pipeline, EM-reset
 * на DB-ошибке, шардинг --shard/--total для параллельных процессов.
 *
 *   php bin/console app:brand:scrape --id=42 --dry-run
 *   php bin/console app:brand:scrape 100 --total=8 --shard=0 --quiet >> var/log/scrape0.log 2>&1 &
 */
#[AsCommand(
    name: 'app:brand:scrape',
    description: 'RAG: скрейп страниц бренда → brand_source_document',
)]
class ScrapeBrandSourcesCommand extends Command
{
    private const MIN_TEXT_CHARS  = 200;   // короче — мусор, не сохраняем
    private const SLEEP_BETWEEN_MS = 800;  // вежливость между брендами
    private const CACHE_TTL_DAYS  = 30;    // свежий doc по URL не перекачиваем
    private const DEFAULT_MAX     = 50;    // максимум источников на бренд

    private int $scraped = 0;
    private int $empty   = 0;
    private int $failed  = 0;
    private int $cached  = 0;   // переиспользовано из кеша (без скачивания)

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry   $managerRegistry,
        private readonly BrandSourceFinder $finder,
        private readonly WebScraperService $scraper,
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
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Игнорировать кеш — перекачать все источники')
            ->addOption('max',     null, InputOption::VALUE_REQUIRED, 'Максимум источников на бренд', (string) self::DEFAULT_MAX)
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', '0')
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $dryRun  = (bool) $input->getOption('dry-run');
        $force   = (bool) $input->getOption('force');
        $max     = max(1, (int) $input->getOption('max'));
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('RAG · скрейп источников брендов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $force, $max);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForScrape($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на скрейп.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к скрейпу: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $force, $max);
            }
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force, int $max): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $urls = $this->finder->discover($brand, $max);
            $io->text(sprintf('  → %s: %d источник(ов)', $name, count($urls)));

            /** @var BrandSourceDocumentRepository $docRepo */
            $docRepo = $this->em->getRepository(BrandSourceDocument::class);
            $cacheSince = (new \DateTime())->modify('-' . self::CACHE_TTL_DAYS . ' days');

            $saved = 0;
            foreach ($urls as $url) {
                $url = mb_substr(rtrim((string) $url, '/'), 0, 1024);
                $existing = $docRepo->findByBrandUrl($brand, $url);

                // КЕШ: свежий doc по этому URL и не --force → переиспользуем без скачивания.
                $createdAt = $existing?->getCreatedAt();
                if (!$force && $existing !== null && $createdAt !== null && $createdAt >= $cacheSince) {
                    $this->cached++;
                    $saved++;
                    continue;
                }

                // trafilatura (если настроена) либо HTTP+DomCrawler — единая точка.
                $text = $this->scraper->fetchCleanText($url);
                if ($text === null || mb_strlen($text) < self::MIN_TEXT_CHARS) {
                    continue;
                }
                $hash = hash('sha256', $text);

                // Контент по этому URL не изменился — оставляем как есть (без переэмбеда).
                if ($existing !== null && $existing->getContentHash() === $hash) {
                    $saved++;
                    continue;
                }
                // Дубль с другого URL того же бренда.
                if ($existing === null && $docRepo->existsForBrandHash($brand, $hash)) {
                    continue;
                }

                $io->text(sprintf('     ✓ %s (%d симв.)', $this->shortUrl($url), mb_strlen($text)));

                if (!$dryRun) {
                    if ($existing !== null) {
                        // контент изменился → обновляем текст, помечаем на переэмбед
                        $existing->setCleanText($text)->setEmbedded(false);
                    } else {
                        $doc = (new BrandSourceDocument())
                            ->setBrand($brand)
                            ->setUrl($url)
                            ->setSourceType($this->classifyUrl($url))
                            ->setRawText(null)            // сырой HTML не храним — только чистый текст
                            ->setCleanText($text);        // сам выставит content_hash + char_count
                        $this->em->persist($doc);
                    }
                }
                $saved++;
            }

            $this->empty += $saved === 0 ? 1 : 0;
            $this->scraped += $saved > 0 ? 1 : 0;

            if (!$dryRun) {
                $pipeline = $this->pipeline($brand);
                $pipeline->setStatus(BrandRagPipeline::STATUS_SCRAPED)
                    ->setScrapedAt(new \DateTime())
                    ->setSourceCount($saved)
                    ->setLastError(null);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recordFailure($brand->getId(), $dryRun);
        }
    }

    /** Гарантирует pipeline-строку для уже управляемого бренда. */
    private function pipeline(Brand $brand): BrandRagPipeline
    {
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        return $repo->getOrCreate($brand);
    }

    private function recordFailure(?int $brandId, bool $dryRun): void
    {
        if ($brandId === null) {
            return;
        }
        if (!$this->em->isOpen()) {
            $this->em = $this->managerRegistry->resetManager();
        } else {
            $this->em->clear();
        }
        if ($dryRun) {
            return;
        }
        try {
            $brand = $this->em->find(Brand::class, $brandId);
            if ($brand) {
                $pipeline = $this->pipeline($brand);
                $pipeline->setStatus(BrandRagPipeline::STATUS_SCRAPE_FAILED)
                    ->setScrapeAttempts($pipeline->getScrapeAttempts() + 1)
                    ->setLastError('scrape failed');
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // глотаем — батч продолжается
        }
    }

    private function classifyUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (['instagram.com', 'vk.com', 't.me', 'telegram.me', 'youtube.com'] as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                return BrandSourceDocument::TYPE_SOCIAL;
            }
        }
        return BrandSourceDocument::TYPE_OFFICIAL;
    }

    private function shortUrl(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? $url);
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Со страницами',        $this->scraped],
            ['Без источников',       $this->empty],
            ['Переисп. из кеша',     $this->cached],
            ['Ошибок',               $this->failed],
        ]);
    }
}
