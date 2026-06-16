<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Entity\BrandSourceUrl;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandSourceDocumentRepository;
use App\Repository\BrandSourceUrlRepository;
use App\Service\WebScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RAG-этап 4 (fetch): дренит очередь brand_source_url. Атомарно клеймит пачку
 * pending-URL своего шарда (claimPending: tier ASC, relevance_score DESC), для
 * каждого скачивает чистый текст (WebScraperService::fetchCleanText — trafilatura
 * либо HTTP+DomCrawler), сохраняет в brand_source_document с тем же per-URL кешем
 * (30д) и дедупом по content_hash, что в монолите app:brand:scrape. URL → fetched
 * на успехе, attempts++/failed на сбое.
 *
 * Когда у бренда не осталось pending/claimed URL — финализирует BrandRagPipeline:
 * STATUS_SCRAPED + scrapedAt + sourceCount (countByBrand) + has_own_site
 * (Фаза B: own_site-документ реально скачан с непустым текстом → true, иначе false).
 *
 * Безопасна для фона: шардинг --shard/--total, reclaimStale на старте (протухший
 * claimed → pending), EM-reset на DB-ошибке.
 *
 *   php bin/console app:brand:fetch --dry-run
 *   php bin/console app:brand:fetch --total=8 --shard=0 --batch=50 --quiet >> var/log/fetch0.log 2>&1 &
 */
#[AsCommand(
    name: 'app:brand:fetch',
    description: 'RAG: дренит brand_source_url → brand_source_document',
)]
class FetchBrandSourcesCommand extends Command
{
    private const MIN_TEXT_CHARS  = 200;   // короче — мусор, не сохраняем
    private const CACHE_TTL_DAYS  = 30;    // свежий doc по URL не перекачиваем
    private const DEFAULT_BATCH   = 50;    // URL за один claim
    private const STALE_MINUTES   = 30;    // claimed дольше — реклеймим в pending
    private const SLEEP_BETWEEN_MS = 300;  // вежливость между URL

    private int $fetched   = 0;
    private int $cached    = 0;   // переиспользовано из кеша (без скачивания)
    private int $empty     = 0;   // скачали, но текст < MIN / дубль
    private int $failed    = 0;
    private int $finalized = 0;   // брендов доведено до SCRAPED

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry   $managerRegistry,
        private readonly WebScraperService $scraper,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', '0')
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
            ->addOption('batch',   null, InputOption::VALUE_REQUIRED, 'URL за один claim', (string) self::DEFAULT_BATCH)
            ->addOption('max-urls', null, InputOption::VALUE_REQUIRED, 'Стоп после ~N URL (0 = дренить до пустой очереди)', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять, показать найденное')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));
        $batch   = max(1, (int) $input->getOption('batch'));
        $maxUrls = max(0, (int) $input->getOption('max-urls'));
        $dryRun  = (bool) $input->getOption('dry-run');

        $io->title('RAG · fetch источников брендов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }
        $io->section(sprintf('shard %d/%d · batch %d%s', $shard, $total, $batch, $maxUrls > 0 ? " · max-urls {$maxUrls}" : ''));

        /** @var BrandSourceUrlRepository $urlRepo */
        $urlRepo = $this->em->getRepository(BrandSourceUrl::class);

        // Протухший claimed (упавший воркер) → обратно в pending, иначе он навсегда
        // заблокирует drain-гейт (count pending+claimed > 0 → бренд не финализируется).
        if (!$dryRun) {
            $reclaimed = $urlRepo->reclaimStale(self::STALE_MINUTES);
            if ($reclaimed > 0) {
                $io->text(sprintf('Реклеймлено протухших claimed: %d', $reclaimed));
            }
        }

        $processed = 0;
        while (true) {
            $claimed = $urlRepo->claimPending($shard, $total, $batch);
            if ($claimed === []) {
                break;
            }
            $processed += count($claimed);

            $touchedBrandIds = $this->processBatch($claimed, $io, $dryRun);

            // Финализация — только после того как строки этой пачки уже терминальны
            // (fetched/failed) и сфлашены: иначе бренд считает свои же URL за claimed.
            if (!$dryRun) {
                foreach ($touchedBrandIds as $brandId) {
                    $this->finalizeIfDrained($brandId, $io);
                }
            }

            // claimPending вернул managed-сущности; чистим UoW только в конце пачки —
            // следующий claim перезапросит свежие строки.
            $this->em->clear();
            gc_collect_cycles(); // циклические ссылки Doctrine иначе текут в долгом прогоне

            // dry-run не меняет статусы → очередь не дренится → бесконечный цикл. Стоп.
            if ($dryRun) {
                break;
            }

            // Ломоть на запуск (для демона): обработанные URL терминальны,
            // следующий запуск продолжит дренаж с места остановки.
            if ($maxUrls > 0 && $processed >= $maxUrls) {
                $io->text(sprintf('Достигнут max-urls (%d) — стоп, очередь продолжит следующий запуск.', $maxUrls));
                break;
            }
        }

        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Обрабатывает одну заклейменную пачку. Возвращает уникальные brand_id,
     * которых она коснулась (для drain-проверки финализации).
     *
     * @param BrandSourceUrl[] $claimed
     * @return int[]
     */
    private function processBatch(array $claimed, SymfonyStyle $io, bool $dryRun): array
    {
        /** @var BrandSourceDocumentRepository $docRepo */
        $docRepo = $this->em->getRepository(BrandSourceDocument::class);
        $cacheSince = (new \DateTime())->modify('-' . self::CACHE_TTL_DAYS . ' days');

        $touched = [];

        foreach ($claimed as $queued) {
            $brand = $queued->getBrand();
            if ($brand === null) {
                continue;
            }
            $touched[$brand->getId()] = true;

            try {
                $this->processUrl($queued, $brand, $docRepo, $cacheSince, $io, $dryRun);
            } catch (\Throwable $e) {
                $io->warning(sprintf('    Ошибка «%s»: %s', $this->shortUrl($queued->getUrl()), $e->getMessage()));
                $this->failed++;
                $this->markFailed($queued, $e->getMessage(), $dryRun);
            }

            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        return array_keys($touched);
    }

    /** Скачивает один URL и сохраняет документ (с кешем/дедупом), помечает URL fetched. */
    private function processUrl(
        BrandSourceUrl $queued,
        Brand $brand,
        BrandSourceDocumentRepository $docRepo,
        \DateTimeInterface $cacheSince,
        SymfonyStyle $io,
        bool $dryRun,
    ): void {
        $url = mb_substr(rtrim($queued->getUrl(), '/'), 0, 1024);
        $existing = $docRepo->findByBrandUrl($brand, $url);

        // КЕШ: свежий doc по этому URL → переиспользуем без скачивания.
        $createdAt = $existing?->getCreatedAt();
        if ($existing !== null && $createdAt !== null && $createdAt >= $cacheSince) {
            $this->cached++;
            $this->markFetched($queued, $dryRun);
            return;
        }

        // Размерные сетки/каталог — сохраняем таблицы (иначе trafilatura --no-tables их выкидывает).
        $type = $queued->getSourceType();
        $keepTables = in_array($type, [BrandSourceUrl::TYPE_OWN_PAGE, BrandSourceUrl::TYPE_PRODUCT_SAMPLE], true)
            || preg_match('~(size|razmer|таблиц)~iu', $url) === 1;
        $text = $this->scraper->fetchCleanText($url, $keepTables);
        if ($text === null || mb_strlen($text) < self::MIN_TEXT_CHARS) {
            // Скачали, но мусор/пусто — URL обработан (не сбой), документа нет.
            $this->empty++;
            $this->markFetched($queued, $dryRun);
            return;
        }

        $hash = hash('sha256', $text);

        // Контент по этому URL не изменился — оставляем как есть (без переэмбеда).
        if ($existing !== null && $existing->getContentHash() === $hash) {
            $this->fetched++;
            $this->markFetched($queued, $dryRun);
            return;
        }
        // Дубль с другого URL того же бренда.
        if ($existing === null && $docRepo->existsForBrandHash($brand, $hash)) {
            $this->empty++;
            $this->markFetched($queued, $dryRun);
            return;
        }

        $io->text(sprintf('     ✓ %s (%d симв., %s)', $this->shortUrl($url), mb_strlen($text), $queued->getSourceType()));

        if (!$dryRun) {
            if ($existing !== null) {
                // контент изменился → обновляем текст + carry-forward, на переэмбед
                $existing
                    ->setSourceType($queued->getSourceType())
                    ->setRelevanceScore($queued->getRelevanceScore())
                    ->setCleanText($text)
                    ->setEmbedded(false);
            } else {
                $doc = (new BrandSourceDocument())
                    ->setBrand($brand)
                    ->setUrl($url)
                    ->setSourceType($queued->getSourceType())   // carry-forward таксономии очереди
                    ->setRelevanceScore($queued->getRelevanceScore())
                    ->setRawText(null)                          // сырой HTML не храним
                    ->setCleanText($text);                      // сам выставит content_hash + char_count
                $this->em->persist($doc);
            }
        }

        $this->fetched++;
        $this->markFetched($queued, $dryRun);
    }

    private function markFetched(BrandSourceUrl $queued, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $queued->setStatus(BrandSourceUrl::STATUS_FETCHED)
            ->setFetchedAt(new \DateTime())
            ->setLastError(null);
        $this->em->flush();
    }

    private function markFailed(BrandSourceUrl $queued, string $error, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        if (!$this->em->isOpen()) {
            $this->em = $this->managerRegistry->resetManager();
            return; // сущность detached после reset — статус доберёт reclaimStale в след. прогон
        }
        try {
            $queued->setStatus(BrandSourceUrl::STATUS_FAILED)
                ->setAttempts($queued->getAttempts() + 1)
                ->setLastError(mb_substr($error, 0, 1000));
            $this->em->flush();
        } catch (\Throwable) {
            // глотаем — пачка продолжается
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            }
        }
    }

    /**
     * Если у бренда не осталось pending/claimed URL — финализируем pipeline.
     * Считаем ПОСЛЕ flush терминальных статусов пачки. STATUS_SCRAPED даже при 0
     * документов (как пустой случай монолита). has_own_site: own_site-документ
     * реально скачан → true, иначе false (Фаза B confirm/demote).
     */
    private function finalizeIfDrained(int $brandId, SymfonyStyle $io): void
    {
        try {
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            }

            /** @var BrandSourceUrlRepository $urlRepo */
            $urlRepo = $this->em->getRepository(BrandSourceUrl::class);
            $remaining = $urlRepo->count([
                'brand'  => $brandId,
                'status' => [BrandSourceUrl::STATUS_PENDING, BrandSourceUrl::STATUS_CLAIMED],
            ]);
            if ($remaining > 0) {
                return; // ещё есть что дренить — финализирует позднейшая пачка
            }

            $brand = $this->em->find(Brand::class, $brandId);
            if ($brand === null) {
                return;
            }

            /** @var BrandSourceDocumentRepository $docRepo */
            $docRepo = $this->em->getRepository(BrandSourceDocument::class);
            $sourceCount = $docRepo->countByBrand($brand);
            $hasOwnSite  = $docRepo->count([
                'brand'      => $brand,
                'sourceType' => BrandSourceUrl::TYPE_OWN_SITE,
            ]) > 0;

            /** @var BrandRagPipelineRepository $pipeRepo */
            $pipeRepo = $this->em->getRepository(BrandRagPipeline::class);
            $pipeline = $pipeRepo->getOrCreate($brand);

            // ГАРД: не понижаем в scraped бренд, уже прошедший дальше — иначе crawl/fetch,
            // доезжая новые own_page-URL, сбрасывает embedded/done/review назад в scraped и
            // перетирает результат (в т.ч. ОПУБЛИКОВАННОЕ done). deferred НЕ протектим —
            // его пере-созревание (scraped→embed→generate при доросшем корпусе) штатно.
            $protected = [
                BrandRagPipeline::STATUS_EMBEDDED,
                BrandRagPipeline::STATUS_GENERATED,
                BrandRagPipeline::STATUS_DONE,
                BrandRagPipeline::STATUS_REVIEW,
            ];
            if (in_array($pipeline->getStatus(), $protected, true)) {
                // только обновим аудит источников, статус не трогаем
                $pipeline->setSourceCount($sourceCount)->setHasOwnSite($hasOwnSite);
                $this->em->flush();
                return;
            }

            $pipeline->setStatus(BrandRagPipeline::STATUS_SCRAPED)
                ->setScrapedAt(new \DateTime())
                ->setSourceCount($sourceCount)
                ->setHasOwnSite($hasOwnSite)
                ->setLastError(null);
            $this->em->flush();

            $this->finalized++;
            $io->text(sprintf(
                '  ✔ финал бренд #%d: %d док., own_site=%s',
                $brandId,
                $sourceCount,
                $hasOwnSite ? 'да' : 'нет',
            ));
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Финализация бренда #%d не удалась: %s', $brandId, $e->getMessage()));
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            }
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
            ['Скачано/обновлено', $this->fetched],
            ['Переисп. из кеша',  $this->cached],
            ['Пусто/дубль',       $this->empty],
            ['Ошибок',            $this->failed],
            ['Финализировано',    $this->finalized],
        ]);
    }
}
