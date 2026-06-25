<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Repository\BrandRepository;
use App\Service\LogoCandidateService;
use App\Service\LogoFetcher;
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
 * Стадия logo: ищет и извлекает логотип бренда из HTML own_site / маркетплейс-страниц.
 *
 * Корпус (brand_source_document) содержит только ЧИСТЫЙ ТЕКСТ — img/svg/og/JSON-LD
 * вырезаны при чистке, сырой HTML не хранится. Поэтому страница перекачивается заново
 * (1 запрос/бренд) через WebScraperService::fetch(); URL берём из brand_source_url.
 *
 * Порядок источников URL: own_site → BrandLink website → marketplace (WB/Lamoda).
 * Найденный логотип кладётся в public_html/images/logos, проставляется brand.logo +
 * markContentChanged → push довезёт ассет на прод (BrandPayloadAssembler шлёт base64).
 *
 * Примеры:
 *   php bin/console app:brand:logo 5 --dry-run
 *   php bin/console app:brand:logo            # 30 брендов
 *   php bin/console app:brand:logo --id=42
 *   php bin/console app:brand:logo 100 --force   # переобработать (в т.ч. not_found/skipped)
 */
#[AsCommand(
    name: 'app:brand:logo',
    description: 'Поиск и извлечение логотипа бренда из HTML own_site/маркетплейс-страниц',
)]
class FetchBrandLogoCommand extends Command
{
    /** Сколько кандидатов попытаться скачать на бренд (по убыванию score). */
    private const MAX_DOWNLOADS = 6;
    private const SLEEP_BETWEEN_MS = 800;

    private int $found   = 0;
    private int $notFound = 0;
    private int $skipped = 0;
    private int $failed  = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry        $managerRegistry,
        private readonly LogoCandidateService   $candidateService,
        private readonly LogoFetcher            $fetcher,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 30)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Обработать один бренд по ID')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Переобработать (включая not_found/skipped и с уже выставленным logo)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять (показать, что нашлось)')
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', 0)
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', 1)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $force   = (bool) $input->getOption('force');
        $dryRun  = (bool) $input->getOption('dry-run');
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('Поиск логотипов брендов');
        if ($dryRun) {
            $io->note('Режим dry-run — файлы не сохраняются, brand.logo не меняется');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд с ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $force);
            $this->printResults($io);
            return Command::SUCCESS;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForLogo(limit: $limit, shard: $shard, total: $total, force: $force),
        );

        if (count($brandIds) === 0) {
            $io->success('Нет брендов для обработки (у всех есть логотип или поиск завершён). --force для переобработки.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к обработке: %d', count($brandIds)));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $force);
            }
            $io->progressAdvance();
            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";
        $io->text(sprintf('  → %s', $name));

        // Уже есть логотип и не --force — пропускаем (на случай прямого --id)
        if (!$force && trim((string) $brand->getLogo()) !== '') {
            $io->text('    ⏭ логотип уже есть');
            return;
        }

        try {
            if (count($this->candidateService->candidatePages($brand)) === 0) {
                $io->text('    ⊘ нет URL-кандидатов (own_site/website/marketplace)');
                $this->finish($brand, BrandRagPipeline::LOGO_SKIPPED, $dryRun);
                $this->skipped++;
                return;
            }

            $picked = $this->findLogo($brand);

            if ($picked === null) {
                $io->text('    ⊘ годного логотипа не нашлось');
                $this->finish($brand, BrandRagPipeline::LOGO_NOT_FOUND, $dryRun);
                $this->notFound++;
                return;
            }

            if ($dryRun) {
                $io->text(sprintf('    [dry-run] нашёл: %s (%s, %dx%d)', $picked['url'], $picked['source'], $picked['width'], $picked['height']));
                $this->found++;
                return;
            }

            $filename = $this->fetcher->save($picked['data']['bytes'], $picked['data']['ext'], $brand->getId());
            $brand->setLogo($filename);
            $this->em->getRepository(BrandRagPipeline::class)->markContentChanged($brand);
            $this->finish($brand, BrandRagPipeline::LOGO_FOUND, false);

            $io->text(sprintf('    ✓ %s ← %s (%s, %dx%d)', $filename, $picked['source'], $picked['data']['ext'], $picked['width'], $picked['height']));
            $this->found++;
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                $this->em->clear();
                if (!$dryRun) {
                    $fresh = $this->em->find(Brand::class, $brand->getId());
                    if ($fresh) {
                        $this->finish($fresh, BrandRagPipeline::LOGO_FAILED, false);
                    }
                }
            }
            $this->failed++;
        }
    }

    /**
     * Берёт кандидатов (отсортированы по score) из LogoCandidateService, качает по
     * убыванию score до первого валидного логотипа. Лимит скачиваний на бренд.
     *
     * @return array{url:string, source:string, width:int, height:int, data:array{bytes:string,ext:string,width:int,height:int}}|null
     */
    private function findLogo(Brand $brand): ?array
    {
        $downloads = 0;

        foreach ($this->candidateService->listCandidates($brand) as $cand) {
            if ($downloads >= self::MAX_DOWNLOADS) {
                return null;
            }
            $downloads++;

            $data = $this->fetcher->download($cand['url'], $cand['favicon']);
            if ($data !== null) {
                return [
                    'url'    => $cand['url'],
                    'source' => $cand['source'],
                    'width'  => $data['width'],
                    'height' => $data['height'],
                    'data'   => $data,
                ];
            }
        }

        return null;
    }

    private function finish(Brand $brand, string $status, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $pipeline = $this->em->getRepository(BrandRagPipeline::class)->getOrCreate($brand);
        $pipeline->setLogoStatus($status);
        $pipeline->setLogoCheckedAt(new \DateTime());
        $this->em->flush();
        $this->em->clear();
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(
            ['Результат', 'Количество'],
            [
                ['Найдено логотипов',          $this->found],
                ['Не нашлось (not_found)',     $this->notFound],
                ['Нет URL-кандидатов (skip)',  $this->skipped],
                ['Ошибок (retry возможен)',    $this->failed],
            ],
        );
    }
}
