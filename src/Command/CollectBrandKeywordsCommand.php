<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Entity\BrandRagPipeline;
use App\Repository\BrandKeywordRepository;
use App\Repository\BrandRagPipelineRepository;
use App\Repository\BrandRepository;
use App\Service\Keyword\KeywordService;
use App\Service\Keyword\WordstatQuotaException;
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
 * Собирает SEO-ключевики (Wordstat) ЗАРАНЕЕ и кэширует в brand_keyword по brand_id.
 * Генерация (app:brand:generate-content) читает готовое — без live-вызова Wordstat.
 *
 * ВАЖНО: у Yandex Cloud Wordstat жёсткая квота 100 запросов/ЧАС на аккаунт.
 * Троттлинг ~37с/запрос (≤97/час). НЕ шардить параллельно — квота общая на аккаунт,
 * параллель только быстрее исчерпает её. При исчерпании команда САМА встаёт на
 * паузу (10 мин) и повторяет тот же бренд, пока окно квоты не восстановится —
 * перезапуск не нужен, можно оставить в окне на долгий прогон. Если поднять квоту
 * в Yandex Cloud — уменьшить SLEEP_BETWEEN_MS.
 *
 *   php bin/console app:brand:keywords --id=42 --dry-run
 *   php bin/console app:brand:keywords 100000 --quiet >> var/log/kw.log 2>&1 &
 */
#[AsCommand(
    name: 'app:brand:keywords',
    description: 'Сбор ключевиков Wordstat в brand_keyword (заранее, для генерации)',
)]
class CollectBrandKeywordsCommand extends Command
{
    // Wordstat: жёсткая квота 100 запросов/ЧАС. 37с между запросами → ≤97/час.
    private const SLEEP_BETWEEN_MS = 37000;
    // На исчерпании квоты — пауза и повтор того же бренда, пока окно не восстановится.
    private const QUOTA_PAUSE_SEC  = 600;   // 10 мин между попытками
    private const QUOTA_MAX_PAUSES = 24;    // предохранитель (~4 ч), потом стоп

    private int $withKeywords = 0;
    private int $totalKeywords = 0;
    private int $empty = 0;
    private int $failed = 0;
    private bool $quotaExhausted = false;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly KeywordService  $keywords,
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
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Пересобрать (удалить старые ключевики бренда)')
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

        $io->title('Сбор ключевиков Wordstat');

        if (!$this->keywords->isConfigured()) {
            $io->warning('Wordstat не настроен (нет WORDSTAT_FOLDER_ID) — сбор пропущен.');
            return Command::SUCCESS;
        }
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processWithQuotaPause((int) $brandId, $io, $dryRun, $force);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForKeywords($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на сбор ключевиков.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $this->processWithQuotaPause((int) $id, $io, $dryRun, $force);
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
            if ($this->quotaExhausted) {  // сработал предохранитель QUOTA_MAX_PAUSES
                $io->progressFinish();
                $io->warning('Квота Wordstat не восстановилась за лимит попыток — остановка (resumable).');
                $this->printResults($io);
                return Command::SUCCESS;
            }
            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Обрабатывает бренд; при исчерпании квоты — пауза и повтор ТОГО ЖЕ бренда. */
    private function processWithQuotaPause(int $brandId, SymfonyStyle $io, bool $dryRun, bool $force): void
    {
        for ($pause = 0; ; ) {
            $brand = $this->em->find(Brand::class, $brandId);
            if ($brand === null) {
                return;
            }
            try {
                $this->processBrand($brand, $io, $dryRun, $force);
                return;
            } catch (WordstatQuotaException) {
                if (++$pause > self::QUOTA_MAX_PAUSES) {
                    $io->warning('Квота Wordstat не восстановилась — остановка (resumable, запусти позже).');
                    $this->quotaExhausted = true;
                    return;
                }
                $io->warning(sprintf(
                    'Квота Wordstat (100/час) исчерпана — пауза %d мин (попытка %d/%d), затем продолжу тот же бренд…',
                    intdiv(self::QUOTA_PAUSE_SEC, 60), $pause, self::QUOTA_MAX_PAUSES,
                ));
                sleep(self::QUOTA_PAUSE_SEC);
            }
        }
    }

    /** Помечает исход опроса Wordstat (found/not_found) — чтобы не переопрашивать. */
    private function markChecked(Brand $brand, string $status): void
    {
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $repo->getOrCreate($brand)
            ->setKeywordsStatus($status)
            ->setKeywordsCheckedAt(new \DateTime());
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $rows = $this->keywords->collect($brand);
            $io->text(sprintf('  → %s: %d ключевик(ов)', $name, count($rows)));

            if ($rows === []) {
                $this->empty++;
                if (!$dryRun) {
                    $this->markChecked($brand, BrandRagPipeline::KW_NOT_FOUND);
                    $this->em->flush();
                    $this->em->clear();
                }
                return;
            }

            /** @var BrandKeywordRepository $kwRepo */
            $kwRepo = $this->em->getRepository(BrandKeyword::class);

            if ($force && !$dryRun) {
                $kwRepo->deleteForBrand($brand);
            }

            $saved = 0;
            foreach ($rows as $row) {
                $phrase = trim((string) ($row['keyword'] ?? ''));
                $type   = ($row['type'] ?? BrandKeyword::TYPE_ORIGIN) === BrandKeyword::TYPE_RELATED
                    ? BrandKeyword::TYPE_RELATED : BrandKeyword::TYPE_ORIGIN;
                if ($phrase === '') {
                    continue;
                }
                if (!$force && $kwRepo->existsPhrase($brand, mb_substr($phrase, 0, 255), $type)) {
                    continue;
                }
                if (!$dryRun) {
                    $kw = (new BrandKeyword())
                        ->setBrand($brand)
                        ->setKeyword($phrase)
                        ->setType($type)
                        ->setMonthlyShows($row['monthlyShows'] ?? null)
                        ->setSource(BrandKeyword::SOURCE_WORDSTAT);
                    $this->em->persist($kw);
                }
                $saved++;
            }

            $this->totalKeywords += $saved;
            $this->withKeywords++;

            if (!$dryRun) {
                $this->markChecked($brand, BrandRagPipeline::KW_FOUND);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (WordstatQuotaException $e) {
            // Часовая квота — не ошибка: пробрасываем в обёртку (пауза + повтор).
            $this->em->clear();
            throw $e;
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                $this->em->clear();
            }
        }
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Брендов с ключевиками', $this->withKeywords],
            ['Всего ключевиков',      $this->totalKeywords],
            ['Без ключевиков',        $this->empty],
            ['Ошибок',                $this->failed],
        ]);
    }
}
