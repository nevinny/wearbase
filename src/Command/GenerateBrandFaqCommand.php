<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandFaq;
use App\Entity\BrandKeyword;
use App\Entity\BrandRagPipeline;
use App\Repository\BrandFaqRepository;
use App\Repository\BrandKeywordRepository;
use App\Repository\BrandRagPipelineRepository;
use App\Service\BrandRagService;
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
 * SEO задача C: FAQ из ключевиков. Берёт бренды status=done без faq_status,
 * выбирает вопросные/длиннохвостые фразы Wordstat → 27b формулирует вопросы и
 * отвечает СТРОГО из фактов (RAG-корпус + описание) → brand_faq + FAQPage JSON-LD.
 *
 * Без ключевиков → faq_status=skipped (вопросы «из головы» без реального спроса —
 * низкий SEO-ROI + риск галлюцинаций). skipped НЕ блокирует публикацию.
 *
 *   php bin/console app:brand:faq --id=42 --dry-run
 *   php bin/console app:brand:faq 10 --no-debug      # стадия GPU-демона
 */
#[AsCommand(
    name: 'app:brand:faq',
    description: 'SEO: FAQ бренда из Wordstat-фраз (grounded-ответы 27b) → brand_faq',
)]
class GenerateBrandFaqCommand extends Command
{
    private const MAX_PHRASES = 10;  // фраз в промпт
    private const MIN_PAIRS   = 2;   // меньше — не сохраняем (один вопрос не FAQ)

    /** Вопросные маркеры в фразе (реальный вопросный интент). */
    private const QUESTION_MARKERS = [
        'как ', 'где ', 'сколько', 'какой', 'какая', 'какие', 'что ', 'чей ',
        'почему', 'можно ли', 'есть ли', 'кто ',
    ];

    /**
     * Шум омонимов в Wordstat (кейс Zatmenie: ночной клуб «ул зураба магкаева 75а»,
     * ресурс-паки, игры) — такие фразы в FAQ не берём. Ответы дополнительно защищает
     * grounded-принцип («нет факта — пропусти»), это фильтр на входе.
     */
    private const PHRASE_DENY_MARKERS = [
        'ул ', 'улица ', 'проспект', 'шоссе ', 'переулок',          // адреса (омоним-заведения)
        'скачать', 'торрент', 'ресурс пак', 'мод ', 'чит',          // игры/файлы
        'смотреть онлайн', 'фильм', 'серия', 'сериал',              // кино
        'http', 'www ', ' ru ', ' com ',                            // URL-обрывки
    ];

    private int $generated = 0;
    private int $skipped   = 0;
    private int $failed    = 0;
    private int $pairs     = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly LlmService      $llm,
        private readonly BrandRagService $rag,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Один бренд по ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Не сохранять, показать Q/A')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Перегенерить (удалить существующий FAQ бренда)')
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
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('SEO · FAQ брендов из ключевиков');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
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

        /** @var \App\Repository\BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findForFaq($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет брендов на FAQ.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к FAQ: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $force);
            }
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $force): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $phrases = $this->selectPhrases($brand);
            if ($phrases === []) {
                // Нет ключевиков (нишевый, KW not_found) — FAQ законно пропускаем.
                $io->text("  → {$name}: нет фраз → skipped");
                $this->setFaqStatus($brand, BrandRagPipeline::FAQ_SKIPPED, $dryRun);
                $this->skipped++;
                return;
            }

            // Факты: описание бренда (он-пейдж истина) + RAG-корпус, если прошёл gate.
            $facts = trim((string) $brand->getDescription());
            $ragContext = $this->rag->retrieve($brand)['context'];
            if ($ragContext !== null) {
                $facts .= "\n\nДополнительные факты из источников:\n" . $ragContext;
            }

            // Физические магазины (brand_store, из обогащения): точные адреса из НАШЕЙ БД —
            // спрос «где находится/купить» отвечаем своими данными, а не шумом омонимов.
            $stores = $this->em->getRepository(\App\Entity\BrandStore::class)
                ->findBy(['brand' => $brand], ['id' => 'ASC'], 10);
            if ($stores !== []) {
                $lines = array_map(static function (\App\Entity\BrandStore $s): string {
                    $line = '- ' . $s->getAddress();
                    if ($s->getWorkHours()) {
                        $line .= ' (часы работы: ' . $s->getWorkHours() . ')';
                    }
                    return $line;
                }, $stores);
                $facts .= "\n\nФизические магазины бренда (точные адреса):\n" . implode("\n", $lines);
                // Гарантированный location-вопрос: спрос почти всегда есть, ответ — точный.
                $phrases[] = 'где купить ' . mb_strtolower((string) $brand->getTitle());
                $phrases = array_slice(array_values(array_unique($phrases)), 0, self::MAX_PHRASES + 1);
            }
            if ($facts === '') {
                $io->text("  → {$name}: нет фактов (пустое описание) → failed");
                $this->setFaqStatus($brand, BrandRagPipeline::FAQ_FAILED, $dryRun);
                $this->failed++;
                return;
            }

            $qa = $this->llm->generateBrandFaq($name, $phrases, $facts, $brand->getCity());

            if (count($qa) < self::MIN_PAIRS) {
                // Модель честно не нашла фактов под фразы — это skipped, не ошибка.
                $io->text(sprintf('  → %s: %d пар(ы) из %d фраз — мало → skipped', $name, count($qa), count($phrases)));
                $this->setFaqStatus($brand, BrandRagPipeline::FAQ_SKIPPED, $dryRun);
                $this->skipped++;
                return;
            }

            $io->text(sprintf('  → %s: %d Q/A из %d фраз', $name, count($qa), count($phrases)));
            foreach ($qa as $i => $pair) {
                $io->text(sprintf('     %d. %s', $i + 1, $pair['question']));
            }

            if (!$dryRun) {
                /** @var BrandFaqRepository $faqRepo */
                $faqRepo = $this->em->getRepository(BrandFaq::class);
                if ($force) {
                    $faqRepo->deleteForBrand($brand);
                }
                foreach ($qa as $i => $pair) {
                    $this->em->persist((new BrandFaq())
                        ->setBrand($brand)
                        ->setQuestion($pair['question'])
                        ->setAnswer($pair['answer'])
                        ->setPosition($i)
                        ->setSource(BrandFaq::SOURCE_WORDSTAT));
                }
                $this->setFaqStatus($brand, BrandRagPipeline::FAQ_DONE, false);
            }

            $this->generated++;
            $this->pairs += count($qa);
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recoverEm();
            $this->setFaqStatus($brand, BrandRagPipeline::FAQ_FAILED, $dryRun, refind: true);
        }
    }

    /**
     * Фразы для FAQ: вопросные (как/где/сколько…) приоритетно + длинный хвост
     * (related, ≥3 слов, низкая частотность — там конкретные интенты).
     *
     * @return string[]
     */
    private function selectPhrases(Brand $brand): array
    {
        /** @var BrandKeywordRepository $kwRepo */
        $kwRepo = $this->em->getRepository(BrandKeyword::class);
        $rows = $kwRepo->findByBrandRanked($brand, 100);

        $question = [];
        $longTail = [];
        $rest     = [];
        foreach ($rows as $kw) {
            $phrase = mb_strtolower(trim($kw->getKeyword()));
            if ($phrase === '') {
                continue;
            }
            foreach (self::PHRASE_DENY_MARKERS as $deny) {
                if (str_contains(' ' . $phrase . ' ', $deny)) {
                    continue 2;
                }
            }
            foreach (self::QUESTION_MARKERS as $marker) {
                if (str_contains($phrase, $marker)) {
                    $question[] = $phrase;
                    continue 2;
                }
            }
            if (str_word_count($phrase, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяabcdefghijklmnopqrstuvwxyz') >= 3) {
                $longTail[] = $phrase;
            } else {
                $rest[] = $phrase; // короткие («{бренд} одежда») — тоже реальный спрос, LLM сам превратит в вопрос
            }
        }

        // Вопросные первыми; хвост — с конца ранжированного списка (низкочастотные = конкретные);
        // добиваем короткими по рангу, чтобы бренды с 3-5 фразами не оставались без FAQ.
        $phrases = array_merge($question, array_reverse($longTail), $rest);

        return array_slice(array_values(array_unique($phrases)), 0, self::MAX_PHRASES);
    }

    private function setFaqStatus(Brand $brand, string $status, bool $dryRun, bool $refind = false): void
    {
        if ($dryRun) {
            return;
        }
        try {
            if ($refind) {
                $brand = $this->em->find(Brand::class, $brand->getId()) ?? $brand;
            }
            /** @var BrandRagPipelineRepository $repo */
            $repo = $this->em->getRepository(BrandRagPipeline::class);
            $repo->getOrCreate($brand)->setFaqStatus($status);
            $this->em->flush();
            $this->em->clear();
        } catch (\Throwable) {
            $this->recoverEm(); // статус не записался — бренд останется в выборке, повторим
        }
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
            ['Брендов с FAQ',        $this->generated],
            ['Всего Q/A пар',        $this->pairs],
            ['Пропущено (нет фраз)', $this->skipped],
            ['Ошибок',               $this->failed],
        ]);
    }
}
