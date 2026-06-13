<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Service\ArticleQaService;
use App\Service\BrandRagService;
use App\Service\ContentValidator;
use App\Service\LlmService;
use App\Service\NearDuplicateDetector;
use App\Service\SeoMetaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:brand:generate-content',
    description: 'Генерация контента и meta для брендов через LLM',
)]
class GenerateBrandContentCommand extends Command
{
    private const MAX_RETRIES = 3;

    // Счётчики результатов
    private int $processed       = 0; // успешно обработано (description + meta)
    private int $metaGenerated   = 0; // обработано только meta (была готовая description)
    private int $failed          = 0; // ошибка при обращении к LLM
    private int $validationFailed = 0; // не прошло валидацию
    private int $qaFailed        = 0; // не прошло QA-гейт (article-qa-toolkit)
    private int $nearDupDropped  = 0; // near-duplicate другого бренда (≥0.85) → review
    private int $deferred        = 0; // grounded-only: корпус не прошёл gate, отложено

    /** EM не readonly — после DB-ошибки пересоздаём через ManagerRegistry (многодневный прогон). */
    private EntityManagerInterface $em;

    /** Лениво собранный корпус shingle-множеств описаний активных брендов (id => set). */
    private ?array $corpusShingles = null;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly LlmService $llmService,
        private readonly ContentValidator $validator,
        private readonly BrandRagService $rag,
        private readonly ArticleQaService $articleQa,
        private readonly NearDuplicateDetector $nearDup,
        private readonly SeoMetaService $seoMeta,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    /** Топ ключевиков бренда (origin раньше related, по частоте) — comma-joined, либо null. */
    private function rankedKeywords(Brand $brand): ?string
    {
        /** @var \App\Repository\BrandKeywordRepository $repo */
        $repo = $this->em->getRepository(\App\Entity\BrandKeyword::class);
        $rows = $repo->findByBrandRanked($brand, 8);
        if ($rows === []) {
            return null;
        }
        $phrases = array_map(static fn(\App\Entity\BrandKeyword $k) => $k->getKeyword(), $rows);

        return mb_substr(implode(', ', $phrases), 0, 200);
    }

    /** Заранее собранные ключевики (brand_keyword) перебивают LLM-вариант в meta.keywords. */
    private function withWordstatKeywords(Brand $brand, array $meta): array
    {
        $kw = $this->rankedKeywords($brand);
        if ($kw !== null) {
            $meta['keywords'] = $kw;
        }
        return $meta;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов для обработки', 50)
            ->addOption('dry-run',       null, InputOption::VALUE_NONE, 'Не сохранять в БД')
            ->addOption('id',            null, InputOption::VALUE_REQUIRED, 'Обработать конкретный бренд по ID')
            ->addOption('meta-only',     null, InputOption::VALUE_NONE, 'Генерировать только meta для брендов с описанием')
            ->addOption('skip-validate', null, InputOption::VALUE_NONE, 'Пропустить валидацию')
            ->addOption('grounded-only', null, InputOption::VALUE_NONE, 'Без RAG-фактов не генерить: бренд → deferred, ждёт дозревания корпуса (description не перезаписывается — legacy-вода зацементировалась бы)')
            ->addOption('shard',         null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', '0')
            ->addOption('total',         null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $limit        = (int) $input->getArgument('limit');
        $dryRun       = $input->getOption('dry-run');
        $brandId      = $input->getOption('id');
        $metaOnly     = $input->getOption('meta-only');
        $skipValidate = $input->getOption('skip-validate');
        $groundedOnly = (bool) $input->getOption('grounded-only');
        $shard        = (int) $input->getOption('shard');
        $total        = max(1, (int) $input->getOption('total'));

        $io->title('Генерация контента для брендов');

        if ($dryRun) {
            $io->note('Режим dry-run — изменения не будут сохранены');
        }

        // Обработка одного бренда по --id
        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд с ID {$brandId} не найден.");
                return Command::FAILURE;
            }

            $io->section(sprintf('Бренд: %s (ID: %d)', $brand->getTitle(), $brand->getId()));
            $this->processBrand($brand, $io, $dryRun, $metaOnly, $skipValidate, $groundedOnly);
            $this->printResults($io, $metaOnly);

            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        // Пакетная обработка: собираем только ID, грузим бренд свежим каждую итерацию,
        // em->clear() после каждого (иначе detached RAG-pipeline/Brand ломают EM в долгом прогоне).
        /** @var \App\Repository\BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $metaOnly
                ? $repo->findWithDescriptionWithoutMeta($limit, $shard, $total)
                : $repo->findWithoutDescription($limit, $shard, $total, $groundedOnly),
        );

        $mode = $metaOnly ? 'только meta' : 'description + meta';
        $io->section(sprintf('Будет обработано: %d брендов (%s, shard %d/%d)', count($brandIds), $mode, $shard, $total));

        if ($brandIds === []) {
            $io->success('Нет брендов для обработки.');
            return Command::SUCCESS;
        }

        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun, $metaOnly, $skipValidate, $groundedOnly);
            }
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
        }

        $io->progressFinish();
        $this->printResults($io, $metaOnly);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Core processing
    // -------------------------------------------------------------------------

    private function processBrand(
        Brand $brand,
        SymfonyStyle $io,
        bool $dryRun,
        bool $metaOnly,
        bool $skipValidate,
        bool $groundedOnly = false,
    ): void {
        $brandName   = $brand->getTitle() ?? 'Unknown';
        $city        = $brand->getCity();
        $existingDescription = trim($brand->getDescription() ?? '');

        $io->text(sprintf(
            '  → %s%s',
            $brandName,
            $city ? " ({$city})" : ''
        ));

        try {
            if ($metaOnly || $existingDescription !== '') {
                // Режим: только meta (description уже есть)
                $this->processMetaOnly($brand, $brandName, $city, $existingDescription, $io, $dryRun, $skipValidate);
            } else {
                // Режим: полная генерация (description + meta)
                $this->processFullGeneration($brand, $brandName, $city, $io, $dryRun, $skipValidate, $groundedOnly);
            }
        } catch (\Throwable $e) {
            $io->warning(sprintf('Ошибка для "%s": %s', $brandName, $e->getMessage()));
            $this->failed++;
            // Если EM сломан DB-ошибкой — пересоздаём, иначе весь батч упадёт.
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                $this->em->clear();
            }
        }
    }

    private function processMetaOnly(
        Brand $brand,
        string $brandName,
        ?string $city,
        string $existingDescription,
        SymfonyStyle $io,
        bool $dryRun,
        bool $skipValidate,
    ): void {
        $context = $this->rag->retrieve($brand)['context'];
        [$meta, $metaErrors] = $this->generateMetaWithRetry($brandName, $existingDescription, $city, $skipValidate, $io, $context, $this->rankedKeywords($brand));

        if (!$skipValidate && !empty($metaErrors)) {
            $io->warning(sprintf('Валидация meta не прошла для "%s": %s', $brandName, implode(', ', $metaErrors)));
            $this->validationFailed++;
            return;
        }

        $io->text(sprintf(
            '    title(%d): %s',
            mb_strlen($meta['title'] ?? ''),
            $meta['title'] ?? ''
        ));
        $io->text(sprintf(
            '    description(%d): %s',
            mb_strlen($meta['description'] ?? ''),
            mb_substr($meta['description'] ?? '', 0, 60) . '…'
        ));

        if (!$dryRun) {
            $this->applyMeta($brand, $this->withWordstatKeywords($brand, $meta));
            $this->em->flush();
            $this->em->clear();
        }

        $this->metaGenerated++;
    }

    private function processFullGeneration(
        Brand $brand,
        string $brandName,
        ?string $city,
        SymfonyStyle $io,
        bool $dryRun,
        bool $skipValidate,
        bool $groundedOnly = false,
    ): void {
        // 0. RAG: достаём реальные факты из Qdrant. context=null → legacy-режим (модель из своих знаний).
        $rag = $this->rag->retrieve($brand);
        $context = $rag['context'];
        if ($context !== null) {
            $io->text(sprintf('    RAG: grounded, чанков %d, score %.2f', $rag['chunks'], $rag['score'] ?? 0));
        }

        // --grounded-only: без фактов не генерим (description потом НЕ перезаписывается,
        // legacy-вода зацементировалась бы). Бренд → deferred; когда корпус дорастёт,
        // fetch вернёт его в scraped → embed → сюда.
        if ($groundedOnly && $context === null) {
            $io->text('    ⏸ grounded-only: корпус не прошёл gate → deferred (ждёт дозревания)');
            if (!$dryRun) {
                /** @var \App\Repository\BrandRagPipelineRepository $repo */
                $repo = $this->em->getRepository(BrandRagPipeline::class);
                $repo->getOrCreate($brand)->setStatus(BrandRagPipeline::STATUS_DEFERRED);
                $this->em->flush();
                $this->em->clear();
            }
            $this->deferred++;

            return;
        }

        // has_own_site=false → у бренда нет собственного сайта, корпус собран только из
        // упоминаний/маркетплейсов. Генерируем, но фиксируем пониженную уверенность.
        // null = discover не прогонялся (legacy) → не сигналим.
        $lowConfidence = $this->pipelineHasOwnSite($brand) === false;
        if ($lowConfidence) {
            $io->text('    ⚠ has_own_site=false — нет собств. сайта, пониженная уверенность grounding');
        }

        // SEO (A/B): топ-фразы Wordstat по частоте — для естественного вплетения в описание и title.
        $keywords = $this->rankedKeywords($brand);

        // 1. Генерация description (без retry — объём текста не гарантирован ретраем, лучше провалиться явно)
        $description = $this->llmService->generateBrandDescription(
            brandName: $brandName,
            city: $city,
            style: $this->getStyleContext($brand),
            facts: $context,
            keywords: $keywords,
        );

        // Отказ модели (факты про другую сущность / недостаточны) — НЕ публикуем и НЕ
        // ретраим (корпус не тот, повтор не поможет). Бренд → review для ручной
        // верификации в админке; старое description не перезаписываем мусором.
        if ($this->validator->isRefusal($description)) {
            $io->warning(sprintf('Отказ модели для "%s" → review (ручная верификация)', $brandName));
            if (!$dryRun) {
                /** @var \App\Repository\BrandRagPipelineRepository $repo */
                $repo = $this->em->getRepository(BrandRagPipeline::class);
                $repo->getOrCreate($brand)
                    ->setStatus(BrandRagPipeline::STATUS_REVIEW)
                    ->setLastError('refusal: факты не о бренде / недостаточны');
                $this->em->flush();
                $this->em->clear();
            }
            $this->deferred++;
            return;
        }

        if (!$skipValidate) {
            $descErrors = $this->validator->validateDescription($description);
            if (!empty($descErrors)) {
                $io->warning(sprintf('Валидация description не прошла для "%s": %s', $brandName, implode(', ', $descErrors)));
                $this->validationFailed++;
                return;
            }

            // 1.5. QA-гейт текста (article-qa-toolkit): AI-почерк, переспам, повторы, вода.
            // FAIL → не сохраняем и не тратим LLM-вызовы на meta; бренд останется без
            // description и будет подобран следующим прогоном (новая генерация — новый шанс).
            $qa = $this->articleQa->check($description);
            if (!$qa['passed']) {
                $io->warning(sprintf(
                    'QA-гейт не прошёл для "%s" (overall %.1f, SB %s, HL %s): %s',
                    $brandName,
                    $qa['metrics']['overall'] ?? 0,
                    $qa['metrics']['spambrain'] ?? '?',
                    $qa['metrics']['human_likeness'] ?? '?',
                    implode('; ', array_slice($qa['reasons'], 0, 4)),
                ));
                $this->qaFailed++;
                return;
            }
            if ($qa['checked']) {
                $io->text(sprintf('    QA: ok (overall %.1f)', $qa['metrics']['overall'] ?? 0));
            }

            // 1.6. Near-duplicate гейт (scaled-content / doorway по однотипным карточкам).
            // Сравниваем с описаниями ОСТАЛЬНЫХ активных брендов: ≥0.85 → DROP (в review,
            // повтор корпуса не лечит — нужна ручная проверка/иной источник); 0.60–0.85 —
            // предупреждение о каннибализации, но публикуем.
            $shingles = $this->nearDup->shingles($description);
            $corpus   = $this->corpus();
            unset($corpus[$brand->getId()]); // не сравниваем с собственным старым описанием
            $near = $this->nearDup->nearest($shingles, $corpus);
            if ($near['score'] >= NearDuplicateDetector::DROP_THRESHOLD) {
                $io->warning(sprintf(
                    'Near-duplicate для "%s" (Jaccard %.2f с brand #%s) → review',
                    $brandName, $near['score'], $near['id'],
                ));
                if (!$dryRun) {
                    /** @var \App\Repository\BrandRagPipelineRepository $repo */
                    $repo = $this->em->getRepository(BrandRagPipeline::class);
                    $repo->getOrCreate($brand)
                        ->setStatus(BrandRagPipeline::STATUS_REVIEW)
                        ->setLastError(sprintf('near-duplicate: Jaccard %.2f с brand #%s', $near['score'], $near['id']));
                    $this->em->flush();
                    $this->em->clear();
                    $this->corpusShingles = null; // EM очищен — корпус перечитаем при следующем обращении
                }
                $this->nearDupDropped++;
                return;
            }
            if ($near['score'] >= NearDuplicateDetector::WARN_THRESHOLD) {
                $io->text(sprintf('    near-dup: каннибализация %.2f с brand #%s (публикуем)', $near['score'], $near['id']));
            }
            // принятое описание попадает в корпус — ловим дубли в пределах одного прогона
            if ($this->corpusShingles !== null) {
                $this->corpusShingles[$brand->getId()] = $shingles;
            }
        }

        // 2. Генерация meta на основе только что созданного description
        [$meta, $metaErrors] = $this->generateMetaWithRetry($brandName, $description, $city, $skipValidate, $io, $context, $keywords);

        if (!$skipValidate && !empty($metaErrors)) {
            $io->warning(sprintf('Валидация meta не прошла для "%s": %s', $brandName, implode(', ', $metaErrors)));
            $this->validationFailed++;
            return;
        }

        // Анонс (краткая выжимка из описания) — если ещё нет.
        if (trim((string) $brand->getAnons()) === '') {
            try {
                $anons = $this->llmService->generateBrandAnons($brandName, $city, $description);
                if ($anons !== '') {
                    $brand->setAnons(mb_substr($anons, 0, 500));
                }
            } catch (\Throwable) {
                // анонс не критичен — пропускаем
            }
        }

        if (!$dryRun) {
            $brand->setDescription($description);
            $this->applyMeta($brand, $this->withWordstatKeywords($brand, $meta));
            $this->markGenerated($brand, $rag);
            $this->em->flush();
            $this->em->clear();
        }

        $this->processed++;
    }

    /**
     * Прочитанный флаг наличия собственного сайта из brand_rag_pipeline:
     * true=есть, false=нет (corpus только из упоминаний), null=discover не прогонялся.
     */
    private function pipelineHasOwnSite(Brand $brand): ?bool
    {
        /** @var \App\Repository\BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(\App\Entity\BrandRagPipeline::class);
        $pipeline = $repo->findOneBy(['brand' => $brand]);

        return $pipeline?->getHasOwnSite();
    }

    /** Отмечает в brand_rag_pipeline факт генерации + использовался ли RAG-контекст. */
    private function markGenerated(Brand $brand, array $rag): void
    {
        /** @var \App\Repository\BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(\App\Entity\BrandRagPipeline::class);
        $repo->getOrCreate($brand)
            ->setStatus(\App\Entity\BrandRagPipeline::STATUS_DONE)
            ->setGeneratedAt(new \DateTime())
            ->setGrounded($rag['context'] !== null)
            ->setTopRetrievalScore($rag['score'] ?? null)
            // Описание/meta записаны → пометить для (ре-)доставки на прод. На первой
            // генерации pushedAt=NULL и так делает бренд eligible; при регенерации
            // уже пушенного (рост корпуса, wb-enrich) — contentChangedAt > pushedAt.
            ->setContentChangedAt(new \DateTime());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Генерирует meta с повторными попытками при ошибках валидации.
     * Возвращает [array $meta, array $errors] — errors пуст при успехе или при skipValidate.
     */
    private function generateMetaWithRetry(
        string $brandName,
        string $description,
        ?string $city,
        bool $skipValidate,
        SymfonyStyle $io,
        ?string $facts = null,
        ?string $keywords = null,
    ): array {
        $meta   = [];
        $errors = [];

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $meta = $this->llmService->generateMetaFromExistingDescription(
                brandName: $brandName,
                existingDescription: $description,
                city: $city,
                facts: $facts,
                keywords: $keywords,
            );

            if ($skipValidate) {
                return [$meta, []];
            }

            $errors = $this->validator->validateMeta($meta);
            if (empty($errors)) {
                return [$meta, []];
            }

            if ($attempt < self::MAX_RETRIES) {
                $io->text(sprintf('    retry %d/%d (meta): %s', $attempt, self::MAX_RETRIES, implode(', ', $errors)));
            }
        }

        return [$meta, $errors];
    }

    private function applyMeta(Brand $brand, array $meta): void
    {
        // _fit по границе слова (не mid-word mb_substr): доктрина «ремонт вместо реджекта»
        $title = trim((string) ($meta['title'] ?? ''));
        $desc  = trim((string) ($meta['description'] ?? ''));
        $brand->setMetaTitle($title !== '' ? $this->seoMeta->fitTitleForRender($title) : null);
        $brand->setMetaDescription($desc !== '' ? $this->seoMeta->fit($desc, SeoMetaService::MAX_DESCRIPTION) : null);
        $brand->setMetaKeywords(mb_substr($meta['keywords'] ?? '', 0, 200) ?: null);
        $brand->setUpdatedAt(new \DateTime());
    }

    private function getStyleContext(Brand $brand): ?string
    {
        $styles = [];
        foreach ($brand->getStyles() as $style) {
            $styles[] = $style->getTitle();
        }

        return $styles ? implode(', ', $styles) : null;
    }

    /**
     * Корпус shingle-множеств описаний активных брендов для near-dup гейта.
     * Лениво и один раз на прогон (id => shingle-set); инвалидируется при em->clear().
     *
     * @return array<int,array<string,true>>
     */
    private function corpus(): array
    {
        if ($this->corpusShingles !== null) {
            return $this->corpusShingles;
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id, description FROM brand
             WHERE status = 'active' AND description IS NOT NULL AND CHAR_LENGTH(description) > 0",
        );

        $this->corpusShingles = [];
        foreach ($rows as $row) {
            $this->corpusShingles[(int) $row['id']] = $this->nearDup->shingles((string) $row['description']);
        }

        return $this->corpusShingles;
    }

    private function printResults(SymfonyStyle $io, bool $metaOnly): void
    {
        $io->newLine();

        $rows = [];

        if (!$metaOnly) {
            $rows[] = ['Сгенерировано (description + meta)', $this->processed];
            $rows[] = ['Не прошло валидацию',               $this->validationFailed];
            $rows[] = ['Не прошло QA-гейт',                  $this->qaFailed];
            $rows[] = ['Near-duplicate → review',            $this->nearDupDropped];
            $rows[] = ['Отложено (grounded-only)',           $this->deferred];
        }

        $rows[] = ['Обновлено только meta', $this->metaGenerated];
        $rows[] = ['Ошибок LLM',           $this->failed];

        $io->table(['Результат', 'Количество'], $rows);
    }
}
