<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Repository\BrandAttributeRepository;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandSourceDocumentRepository;
use App\Service\Agent\ProdBrandPusher;
use App\Service\LlmService;
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
 * RAG-этап: извлечение структурированных атрибутов бренда из накопленного краула
 * (стили/категории/gender/размеры/материалы/сегмент/гео) через qwen → brand_attribute.
 * Приоритет в агрегат — size/category документы (там размерный ряд и ассортимент).
 *
 *   php bin/console app:brand:extract --id=42 --dry-run
 *   php bin/console app:brand:extract 10 --no-debug   # GPU-стадия демона
 */
#[AsCommand(
    name: 'app:brand:extract',
    description: 'RAG: извлечение атрибутов бренда из краула → brand_attribute',
)]
class ExtractBrandAttributesCommand extends Command
{
    private const MAX_FACTS_CHARS = 14_000; // бюджет контекста qwen (приоритет size/category)

    private int $extracted = 0;
    private int $skipped   = 0;
    private int $failed    = 0;
    private int $attrs     = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly LlmService      $llm,
        private readonly ProdBrandPusher $pusher,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Один бренд по ID')
            ->addOption('ids',     null, InputOption::VALUE_REQUIRED, 'Список ID через запятую (точечный набор, минуя finder)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять, показать извлечённое')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Переизвлечь (удалить enrichment-атрибуты)')
            ->addOption('fields-only', null, InputOption::VALUE_NONE, 'Backfill только brand.city/foundingYear, атрибуты не трогать (без churn)')
            ->addOption('published-missing', null, InputOption::VALUE_NONE, 'Только опубликованные на проде (done+pushed) с пустыми city/country/год — для бэкафилла live-брендов')
            ->addOption('push', null, InputOption::VALUE_NONE, 'После обогащения city/country/год сразу доставить бренд на прод (agent-API upsert)')
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
        $force   = (bool) $input->getOption('force');
        $fieldsOnly = (bool) $input->getOption('fields-only');
        $push    = (bool) $input->getOption('push');
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('RAG · извлечение атрибутов брендов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $force, $fieldsOnly, $push);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $publishedMissing = (bool) $input->getOption('published-missing');
        $idsOpt = $input->getOption('ids');

        if ($idsOpt) {
            // Точечный набор ID (минуя finder): для бэкафилла конкретного списка (напр. live-бренды прода).
            $brandIds = array_values(array_filter(array_map('intval', explode(',', (string) $idsOpt))));
        } else {
            /** @var \App\Repository\BrandRepository $repo */
            $repo = $this->em->getRepository(Brand::class);
            $brandIds = array_map(static fn(Brand $b) => $b->getId(), $publishedMissing
                ? $repo->findPublishedMissingFields($limit, $shard, $total)
                : $repo->findForExtract($limit, $shard, $total));
        }

        if ($brandIds === []) {
            $io->success('Нет брендов на извлечение.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к извлечению: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $force, $fieldsOnly, $push);
            }
            $io->progressAdvance();
            gc_collect_cycles();
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force, bool $fieldsOnly = false, bool $push = false): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $facts = $this->aggregateFacts($brand);
            if (trim($facts) === '') {
                $io->text("  → {$name}: нет корпуса → skipped");
                $this->setStatus($brand, BrandRagPipeline::ATTR_SKIPPED, $dryRun);
                $this->skipped++;
                return;
            }

            $a = $this->llm->extractBrandAttributes($name, $facts);

            // Уплощаем в (name, value)-пары
            $pairs = [];
            foreach ($a['styles'] as $v)     { $pairs[] = [BrandAttribute::NAME_STYLE, $v]; }
            foreach ($a['categories'] as $v) { $pairs[] = [BrandAttribute::NAME_CATEGORY, $v]; }
            foreach ($a['sizes'] as $v)      { $pairs[] = [BrandAttribute::NAME_SIZE, $v]; }
            foreach ($a['materials'] as $v)  { $pairs[] = [BrandAttribute::NAME_MATERIAL, $v]; }
            if ($a['gender'])        { $pairs[] = [BrandAttribute::NAME_GENDER, $a['gender']]; }
            if ($a['price_segment']) { $pairs[] = [BrandAttribute::NAME_PRICE_SEGMENT, $a['price_segment']]; }
            if ($a['geo'])           { $pairs[] = [BrandAttribute::NAME_GEO, $a['geo']]; }

            // Первоклассные поля бренда из грунтованного extract: город базирования и год
            // основания (geo-атрибут — это страна/регион, city — именно город для фактоида/хаба).
            $brandFieldSet = false;
            // City заполняем ТОЛЬКО если пуст: LLM возвращает разнобой форм («москва»/«московский»),
            // перезапись консолидированных значений фрагментировала бы city-хабы (разные slug'и).
            // Неверные значения правятся точечной курацией, не автоперезаписью.
            if ($a['city'] && trim((string) $brand->getCity()) === '') {
                $brand->setCity(mb_substr($a['city'], 0, 100));
                $brandFieldSet = true;
            }
            // Страна происхождения → brand.country (для решения B: фильтр иностранных в SEO-подборках).
            // Заполняем только если пуст (как city — без автоперезаписи курированных значений).
            if (($a['country'] ?? null) && trim((string) $brand->getCountry()) === '') {
                $brand->setCountry(mb_substr($a['country'], 0, 50));
                $brandFieldSet = true;
            }
            if ($a['founding_year']) {
                $brand->setFoundingYear($a['founding_year']);
                $brandFieldSet = true;
            }

            $io->text(sprintf('  → %s: %d атрибут(ов) [cat:%d size:%d style:%d mat:%d]%s%s',
                $name, count($pairs), count($a['categories']), count($a['sizes']), count($a['styles']), count($a['materials']),
                $a['city'] ? ' city:' . $a['city'] : '', $a['founding_year'] ? ' год:' . $a['founding_year'] : ''));

            if (!$dryRun && $fieldsOnly) {
                // Backfill: атрибуты не трогаем (нет churn/дублей), пишем только brand.city/foundingYear.
                if ($brandFieldSet) {
                    $this->setStatus($brand, BrandRagPipeline::ATTR_DONE, false); // flush + contentChanged → ре-доставка
                } else {
                    $this->em->clear();
                }
            } elseif (!$dryRun) {
                /** @var BrandAttributeRepository $attrRepo */
                $attrRepo = $this->em->getRepository(BrandAttribute::class);
                if ($force) {
                    $attrRepo->deleteEnrichmentForBrand($brand);
                }
                $seen = [];
                foreach ($pairs as [$n, $v]) {
                    $key = $n . '|' . mb_strtolower($v);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $this->em->persist((new BrandAttribute())
                        ->setBrand($brand)
                        ->setName($n)
                        ->setValue(mb_substr($v, 0, 255)));
                }
                // ATTR_DONE (с пометкой ре-доставки) если есть атрибуты ИЛИ заполнены city/год.
                $this->setStatus($brand, ($pairs === [] && !$brandFieldSet) ? BrandRagPipeline::ATTR_SKIPPED : BrandRagPipeline::ATTR_DONE, false);
            }

            // --push: сразу доставить обогащённый бренд на прод (город/страна/год попадут в live).
            if ($push && $brandFieldSet && !$dryRun) {
                $this->pushBrand($brand, $io);
            }

            $this->extracted += $pairs !== [] ? 1 : 0;
            $this->attrs += count($pairs);
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recoverEm();
        }
    }

    /** Доставка обогащённого бренда на прод (ProdBrandPusher). Fail-open: сбой не рушит extract. */
    private function pushBrand(Brand $brand, SymfonyStyle $io): void
    {
        if (!$this->pusher->isConfigured()) {
            $io->warning('    --push: PROD_API_URL/AGENT_API_TOKEN/SECRET не заданы — пропуск.');
            return;
        }
        try {
            $data = $this->pusher->upsert($brand);
            $io->text(sprintf('    → прод: %s (city:%s)', $data['status'], $brand->getCity() ?: '—'));
        } catch (\Throwable $e) {
            $io->warning('    → пуш-ошибка: ' . mb_substr($e->getMessage(), 0, 150));
        }
    }

    /**
     * Агрегат корпуса для LLM: сперва size/own_page/product_sample документы
     * (там размерный ряд и ассортимент), потом остальное — до бюджета.
     */
    private function aggregateFacts(Brand $brand): string
    {
        /** @var BrandSourceDocumentRepository $docRepo */
        $docRepo = $this->em->getRepository(BrandSourceDocument::class);
        $docs = $docRepo->findByBrand($brand);

        // Приоритет: документы с размерами/ассортиментом вперёд.
        usort($docs, static function (BrandSourceDocument $a, BrandSourceDocument $b): int {
            $score = static function (BrandSourceDocument $d): int {
                $t = $d->getSourceType();
                if ($t === 'product_sample') return 3;
                if (preg_match('~(size|razmer|таблиц)~iu', (string) $d->getUrl())) return 4;
                if ($t === 'own_page' || $t === 'own_site') return 2;
                return 1;
            };
            return $score($b) <=> $score($a);
        });

        $facts = '';
        foreach ($docs as $doc) {
            $chunk = trim((string) $doc->getCleanText());
            if ($chunk === '') {
                continue;
            }
            $facts .= $chunk . "\n\n";
            if (mb_strlen($facts) >= self::MAX_FACTS_CHARS) {
                break;
            }
        }

        return mb_substr($facts, 0, self::MAX_FACTS_CHARS);
    }

    private function setStatus(Brand $brand, string $status, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $pipeline = $repo->getOrCreate($brand)
            ->setAttributesStatus($status)
            ->setExtractedAt(new \DateTime());
        // Атрибуты реально записаны → пометить для ре-доставки на прод.
        if ($status === BrandRagPipeline::ATTR_DONE) {
            $pipeline->setContentChangedAt(new \DateTime());
        }
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
            ['Брендов с атрибутами', $this->extracted],
            ['Всего атрибутов',      $this->attrs],
            ['Пропущено',            $this->skipped],
            ['Ошибок',               $this->failed],
        ]);
    }
}
