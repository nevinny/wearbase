<?php

namespace App\Command;

use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Entity\BrandSourceUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Сброс «фантомных» строк brand_rag_pipeline: статус заявляет прогресс
 * (scraped/embedded/generated/*_failed), но у бренда 0 brand_source_document —
 * grounding-данных нет. Такой статус выставлен без результата (fetch не дал
 * документов / другой прогон / сбой) → при generate-content gate провалится и
 * пойдёт ungrounded legacy-генерация.
 *
 * URL-очередь у фантомов обычно ЕСТЬ (discover отработал), но в статусе
 * fetched/failed — fetch её больше не тронет. Поэтому сброс делает ДВЕ вещи:
 *   1. pipeline: status→pending, *_at→null, sourceCount→0, hasOwnSite→null
 *      (discover снова подхватит бренд — выборка по discoveredAt IS NULL);
 *   2. brand_source_url бренда: status→pending (fetch перечитает очередь).
 * Так бренд проходит конвейер заново: discover → fetch → embed.
 *
 * Dry-run по умолчанию; запись только с --apply. Долгий прогон — с --no-debug.
 *
 *   php bin/console app:brand:pipeline:reset-phantoms                 # отчёт
 *   php bin/console app:brand:pipeline:reset-phantoms --apply         # сброс
 *   php bin/console app:brand:pipeline:reset-phantoms --include-deferred --apply
 */
#[AsCommand(
    name: 'app:brand:pipeline:reset-phantoms',
    description: 'Сброс фантомных pipeline-статусов (прогресс заявлен, источников нет)',
)]
class ResetPhantomPipelineCommand extends Command
{
    /** Статусы, при которых ОЖИДАЮТСЯ документы — если их нет, это фантом. */
    private const PHANTOM_STATUSES = [
        BrandRagPipeline::STATUS_SCRAPED,
        BrandRagPipeline::STATUS_EMBEDDED,
        BrandRagPipeline::STATUS_GENERATED,
        BrandRagPipeline::STATUS_EMBED_FAILED,
        BrandRagPipeline::STATUS_GENERATE_FAILED,
    ];

    private const FLUSH_BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Записать сброс (без флага — только отчёт)')
            ->addOption('include-deferred', null, InputOption::VALUE_NONE, 'Включить deferred (осознанно припаркованные)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $apply   = (bool) $input->getOption('apply');
        $statuses = self::PHANTOM_STATUSES;
        if ($input->getOption('include-deferred')) {
            $statuses[] = BrandRagPipeline::STATUS_DEFERRED;
        }

        $io->title('RAG · сброс фантомных pipeline-статусов');
        $io->writeln($apply ? '<comment>РЕЖИМ ЗАПИСИ (--apply)</comment>' : 'dry-run — только отчёт (для записи добавьте --apply)');

        // Фантом = статус заявляет прогресс, но у бренда нет документов (grounding пуст).
        $phantoms = $this->em->createQueryBuilder()
            ->select('p')
            ->from(BrandRagPipeline::class, 'p')
            ->where('p.status IN (:statuses)')
            ->andWhere('NOT EXISTS (' . $this->em->createQueryBuilder()
                ->select('d.id')->from(BrandSourceDocument::class, 'd')
                ->where('d.brand = p.brand')->getDQL() . ')')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getResult();

        if ($phantoms === []) {
            $io->success('Фантомов не найдено.');
            return Command::SUCCESS;
        }

        // Сводка по исходным статусам
        $byStatus = [];
        foreach ($phantoms as $p) {
            $byStatus[$p->getStatus()] = ($byStatus[$p->getStatus()] ?? 0) + 1;
        }
        ksort($byStatus);
        $rows = [];
        foreach ($byStatus as $status => $n) {
            $rows[] = [$status, $n];
        }
        $io->section(sprintf('Найдено фантомов: %d', count($phantoms)));
        $io->table(['исходный статус', 'кол-во'], $rows);

        if (!$apply) {
            $sample = array_slice(array_map(static fn($p) => $p->getBrand()?->getId(), $phantoms), 0, 30);
            $io->writeln('Примеры brand_id: ' . implode(', ', array_filter($sample)));
            $io->note('Запись не выполнена. Повторите с --apply для сброса в pending.');
            return Command::SUCCESS;
        }

        $brandIds = [];
        $reset = 0;
        foreach ($phantoms as $p) {
            $p->setStatus(BrandRagPipeline::STATUS_PENDING)
                ->setDiscoveredAt(null)
                ->setScrapedAt(null)
                ->setEmbeddedAt(null)
                ->setGeneratedAt(null)
                ->setSourceCount(0)
                ->setHasOwnSite(null);

            $bid = $p->getBrand()?->getId();
            if ($bid !== null) {
                $brandIds[] = $bid;
            }

            if (++$reset % self::FLUSH_BATCH === 0) {
                $this->em->flush();
                $io->writeln(sprintf('  …сброшено %d', $reset));
            }
        }
        $this->em->flush();

        // Переочередь URL фантомов: fetched/failed → pending, чтобы fetch перечитал.
        $requeued = 0;
        foreach (array_chunk($brandIds, 500) as $chunk) {
            $requeued += (int) $this->em->createQuery(
                'UPDATE ' . BrandSourceUrl::class . ' u SET u.status = :pending
                 WHERE u.brand IN (:ids) AND u.status IN (:done)'
            )
                ->setParameter('pending', BrandSourceUrl::STATUS_PENDING)
                ->setParameter('done', [BrandSourceUrl::STATUS_FETCHED, BrandSourceUrl::STATUS_FAILED])
                ->setParameter('ids', $chunk)
                ->execute();
        }

        $io->success(sprintf(
            'Сброшено pipeline в pending: %d; переочередено URL: %d. Запустите app:brand:discover → fetch → embed.',
            $reset,
            $requeued
        ));

        return Command::SUCCESS;
    }
}
