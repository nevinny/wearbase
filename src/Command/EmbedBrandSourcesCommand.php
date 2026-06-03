<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandRepository;
use App\Repository\BrandSourceDocumentRepository;
use App\Service\EmbeddingService;
use App\Service\TextChunker;
use App\Service\VectorStoreService;
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
 * RAG-этап 2: чанкует скрейпленный текст, считает эмбеддинги (bge-m3) и заливает
 * векторы в Qdrant (payload: brand_id, doc_id, chunk_index, text). Статус → embedded.
 *
 *   php bin/console app:brand:embed --id=42
 *   php bin/console app:brand:embed 100 --quiet >> var/log/embed.log 2>&1 &
 */
#[AsCommand(
    name: 'app:brand:embed',
    description: 'RAG: чанки brand_source_document → эмбеддинги → Qdrant',
)]
class EmbedBrandSourcesCommand extends Command
{
    private const EMBED_BATCH = 32;

    private int $embedded = 0;
    private int $failed   = 0;
    private int $points   = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry   $managerRegistry,
        private readonly EmbeddingService  $embedder,
        private readonly VectorStoreService $vectors,
        private readonly TextChunker       $chunker,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Один бренд по ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не эмбеддить, показать чанки')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Перезалить (удалить векторы бренда)')
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
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('RAG · эмбеддинг источников в Qdrant');
        if ($dryRun) {
            $io->note('dry-run — без эмбеддинга');
        }

        if (!$dryRun) {
            try {
                $this->vectors->ensureCollection();
            } catch (\Throwable $e) {
                $io->error('Qdrant недоступен/несовместим: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $force);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForEmbed($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на эмбеддинг.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к эмбеддингу: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $force);
            }
            $io->progressAdvance();
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            /** @var BrandSourceDocumentRepository $docRepo */
            $docRepo = $this->em->getRepository(BrandSourceDocument::class);

            if ($force && !$dryRun) {
                $this->vectors->deleteByBrand($brand->getId());
                foreach ($docRepo->findByBrand($brand) as $d) {
                    $d->setEmbedded(false);
                }
                $this->em->flush();
            }

            $docs = $force ? $docRepo->findByBrand($brand) : $docRepo->findUnembeddedByBrand($brand);

            // Собираем чанки + метаданные точек.
            $chunks = [];   // texts to embed
            $meta   = [];   // parallel: [docId, hash, chunkIndex, url, type]
            foreach ($docs as $doc) {
                $i = 0;
                foreach ($this->chunker->chunk((string) $doc->getCleanText()) as $piece) {
                    $chunks[] = $piece;
                    $meta[] = [
                        'docId'     => $doc->getId(),
                        'hash'      => $doc->getContentHash(),
                        'idx'       => $i++,
                        'url'       => $doc->getUrl(),
                        'type'      => $doc->getSourceType(),
                        'relevance' => $doc->getRelevanceScore(),
                    ];
                }
            }

            $io->text(sprintf('  → %s: %d док, %d чанк(ов)', $name, count($docs), count($chunks)));

            if ($dryRun || $chunks === []) {
                if ($chunks === [] && !$dryRun) {
                    // нет текста — всё равно продвигаем статус, генерация уйдёт в fallback
                    $this->advance($brand, 0);
                    $this->em->flush();
                    $this->em->clear();
                }
                return;
            }

            // Эмбеддинг по-чанково: bge-m3 изредка выдаёт NaN на мусорном тексте,
            // что роняет весь батч (ollama не сериализует NaN в JSON). По одному —
            // сбойный чанк пропускаем, остальные сохраняем.
            $brandId = $brand->getId();
            $points = [];
            $skipped = 0;
            foreach ($chunks as $k => $text) {
                try {
                    $vec = $this->embedder->embed($text);
                } catch (\Throwable) {
                    $skipped++;
                    continue;
                }
                $m = $meta[$k];
                $points[] = [
                    'id'      => VectorStoreService::pointId($brandId, $m['hash'], $m['idx']),
                    'vector'  => $vec,
                    'payload' => [
                        'brand_id'    => $brandId,
                        'doc_id'      => $m['docId'],
                        'chunk_index' => $m['idx'],
                        'source_url'  => $m['url'],
                        'source_type' => $m['type'],
                        'relevance'   => $m['relevance'],
                        'text'        => $text,
                    ],
                ];
                if (count($points) >= self::EMBED_BATCH) {
                    $this->vectors->upsertPoints($points);
                    $this->points += count($points);
                    $points = [];
                }
            }
            if ($points !== []) {
                $this->vectors->upsertPoints($points);
                $this->points += count($points);
            }
            if ($skipped > 0) {
                $io->text(sprintf('     ⚠ пропущено чанков (NaN/ошибка): %d', $skipped));
            }

            foreach ($docs as $doc) {
                $doc->setEmbedded(true);
            }
            $this->advance($brand, count($chunks));
            $this->em->flush();
            $this->em->clear();
            $this->embedded++;
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recordFailure($brand->getId(), $dryRun);
        }
    }

    private function advance(Brand $brand, int $chunkCount): void
    {
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $repo->getOrCreate($brand)
            ->setStatus(BrandRagPipeline::STATUS_EMBEDDED)
            ->setEmbeddedAt(new \DateTime())
            ->setLastError(null);
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
                /** @var BrandRagPipelineRepository $repo */
                $repo = $this->em->getRepository(BrandRagPipeline::class);
                $p = $repo->getOrCreate($brand);
                $p->setStatus(BrandRagPipeline::STATUS_EMBED_FAILED)
                    ->setEmbedAttempts($p->getEmbedAttempts() + 1)
                    ->setLastError('embed failed');
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // батч продолжается
        }
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Брендов заэмбеддено', $this->embedded],
            ['Точек в Qdrant',      $this->points],
            ['Ошибок',              $this->failed],
        ]);
    }
}
