<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandRepository;
use App\Service\WildberriesClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:brand:wb-enrich',
    description: 'Ингест товаров с Wildberries в корпус бренда + переэмбедд + регенерация grounded-описания',
)]
class WbEnrichCommand extends Command
{
    private const SLEEP_BETWEEN_BRANDS = 2_000_000;

    private int $done = 0;
    private int $noProducts = 0;
    private int $failed = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly WildberriesClient $wbClient,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за один запуск', 50)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Обработать один бренд по ID')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Номер шарда для параллельных процессов', 0)
            ->addOption('total', null, InputOption::VALUE_REQUIRED, 'Всего шардов', 1)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять в БД')
            ->addOption('skip-llm', null, InputOption::VALUE_NONE, 'Не запускать embed+generate после ингеста')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $shard = (int) $input->getOption('shard');
        $total = (int) $input->getOption('total');
        $dryRun = $input->getOption('dry-run');
        $skipLlm = $input->getOption('skip-llm');

        $io->title('Ингест товаров с Wildberries');

        if ($dryRun) {
            $io->note('Режим dry-run — изменения не будут сохранены');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд с ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $skipLlm);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);

        $brandIds = array_map(
            fn(Brand $b) => $b->getId(),
            $repo->findForWbEnrich(limit: $limit, shard: $shard, total: $total),
        );

        if (count($brandIds) === 0) {
            $io->success('Нет брендов для обработки. Все уже проверены.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к обработке: %d', count($brandIds)));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if (!$brand) {
                $io->progressAdvance();
                continue;
            }

            $this->processBrand($brand, $io, $dryRun, $skipLlm);
            $io->progressAdvance();
            usleep(self::SLEEP_BETWEEN_BRANDS);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $skipLlm): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";
        $io->text(sprintf('  → %s', $name));

        try {
            $prods = $this->wbClient->searchBrandProducts($name);

            if (empty($prods)) {
                $io->text('    ⊘ товары не найдены');
                if (!$dryRun) {
                    $this->setWbStatus($brand, 'no_products');
                }
                $this->noProducts++;
                return;
            }

            $io->text(sprintf('    найдено товаров: %d', count($prods)));

            if ($dryRun) {
                $this->previewProducts($brand, $prods, $io);
                $this->done++;
                return;
            }

            $text = $this->buildDocumentText($brand, $prods);
            $this->saveDocument($brand, $text, 'done');

            $io->text('    ✓ документ сохранён');

            if (!$skipLlm) {
                $this->runEmbedAndGenerate($brand, $io);
            }

            $this->done++;
        } catch (\Exception $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));

            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                $this->em->clear();
            }

            if (!$dryRun) {
                try {
                    $freshBrand = $this->em->find(Brand::class, $brand->getId());
                    if ($freshBrand) {
                        $this->setWbStatus($freshBrand, 'error');
                    }
                } catch (\Exception $inner) {
                    $io->warning(sprintf('    Не удалось сохранить статус error: %s', $inner->getMessage()));
                }
            }

            $this->failed++;
        }
    }

    /**
     * Сохраняет документ и проставляет wb_status в одном flush,
     * чтобы избежать detached entity между операциями.
     */
    private function saveDocument(Brand $brand, string $text, string $status): void
    {
        $slug = $brand->getSlug() ?? (string) $brand->getId();
        $url = 'wb:' . $slug;

        $existing = $this->em->getRepository(BrandSourceDocument::class)->findOneBy([
            'brand' => $brand,
            'url' => $url,
        ]);
        if ($existing !== null) {
            $this->em->remove($existing);
        }

        $doc = new BrandSourceDocument();
        $doc->setBrand($brand);
        $doc->setUrl($url);
        $doc->setSourceType('marketplace');
        $doc->setCleanText($text);
        $doc->setRelevanceScore(0.8);
        $this->em->persist($doc);

        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $pipeline = $repo->getOrCreate($brand);
        $pipeline->setWbStatus($status);
        $pipeline->setWbCheckedAt(new \DateTime());

        $this->em->flush();
        $this->em->clear();
    }

    private function setWbStatus(Brand $brand, string $status): void
    {
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $pipeline = $repo->getOrCreate($brand);
        $pipeline->setWbStatus($status);
        $pipeline->setWbCheckedAt(new \DateTime());
        $this->em->flush();
        $this->em->clear();
    }

    private function buildDocumentText(Brand $brand, array $products): string
    {
        $names = array_map(fn($p) => $p['name'], $products);
        $categories = array_unique(array_filter(array_map(fn($p) => $p['subj_name'] ?? '', $products)));

        return sprintf(
            "Бренд %s на Wildberries. Ассортимент (%d товаров): %s.\nКатегории: %s.",
            $brand->getTitle(),
            count($products),
            implode('; ', $names),
            implode(', ', $categories),
        );
    }

    private function previewProducts(Brand $brand, array $products, SymfonyStyle $io): void
    {
        $io->text('    [dry-run] Найдены товары:');
        foreach ($products as $p) {
            $cat = !empty($p['subj_name']) ? " ({$p['subj_name']})" : '';
            $io->text("      • {$p['name']}{$cat}");
        }
        $text = $this->buildDocumentText($brand, $products);
        $io->text('    [dry-run] Текст документа:');
        $io->text("      {$text}");
    }

    private function runEmbedAndGenerate(Brand $brand, SymfonyStyle $io): void
    {
        $id = $brand->getId();

        $io->text('    ⏳ переэмбеддинг...');
        $embed = new Process(['php', 'bin/console', 'app:brand:embed', "--id={$id}", '--no-debug']);
        $embed->setTimeout(300);
        $embed->run();
        if (!$embed->isSuccessful()) {
            $io->warning("    embed failed: {$embed->getErrorOutput()}");
            return;
        }

        $io->text('    ⏳ генерация grounded-описания...');
        $generate = new Process(['php', 'bin/console', 'app:brand:generate-content', "--id={$id}", '--grounded-only', '--no-debug']);
        $generate->setTimeout(300);
        $generate->run();
        if (!$generate->isSuccessful()) {
            $io->warning("    generate-content failed: {$generate->getErrorOutput()}");
            return;
        }

        $io->text('    ✓ grounded-описание сгенерировано');
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(
            ['Результат', 'Количество'],
            [
                ['Обработано (есть товары)', $this->done],
                ['Товары не найдены', $this->noProducts],
                ['Ошибок', $this->failed],
            ],
        );
    }
}
