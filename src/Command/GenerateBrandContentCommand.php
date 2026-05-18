<?php

namespace App\Command;

use App\Entity\Brand;
use App\Service\ContentValidator;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LlmService $llmService,
        private readonly ContentValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов для обработки', 50)
            ->addOption('dry-run',       null, InputOption::VALUE_NONE, 'Не сохранять в БД')
            ->addOption('id',            null, InputOption::VALUE_REQUIRED, 'Обработать конкретный бренд по ID')
            ->addOption('meta-only',     null, InputOption::VALUE_NONE, 'Генерировать только meta для брендов с описанием')
            ->addOption('skip-validate', null, InputOption::VALUE_NONE, 'Пропустить валидацию')
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

        $io->title('Генерация контента для брендов');

        if ($dryRun) {
            $io->note('Режим dry-run — изменения не будут сохранены');
        }

        // Обработка одного бренда по --id
        if ($brandId !== null) {
            $brand = $this->entityManager->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд с ID {$brandId} не найден.");
                return Command::FAILURE;
            }

            $io->section(sprintf('Бренд: %s (ID: %d)', $brand->getTitle(), $brand->getId()));
            $this->processBrand($brand, $io, $dryRun, $metaOnly, $skipValidate);
            $this->printResults($io, $metaOnly);

            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        // Пакетная обработка
        $repo = $this->entityManager->getRepository(Brand::class);

        $toProcess = $metaOnly
            ? $repo->findWithDescriptionWithoutMeta($limit)
            : $repo->findWithoutDescription($limit);

        $io->section(sprintf(
            'Будет обработано: %d брендов (%s)',
            count($toProcess),
            $metaOnly ? 'только meta' : 'description + meta'
        ));

        $mode = $metaOnly ? 'только meta' : 'description + meta';
        $io->section(sprintf('Будет обработано: %d брендов (%s)', count($toProcess), $mode));

        if (count($toProcess) === 0) {
            $io->success('Нет брендов для обработки.');
            return Command::SUCCESS;
        }

        $io->progressStart(count($toProcess));

        foreach ($toProcess as $brand) {
            $this->processBrand($brand, $io, $dryRun, $metaOnly, $skipValidate);
            $io->progressAdvance();
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
                $this->processFullGeneration($brand, $brandName, $city, $io, $dryRun, $skipValidate);
            }
        } catch (\Exception $e) {
            $io->warning(sprintf('Ошибка для "%s": %s', $brandName, $e->getMessage()));
            $this->failed++;
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
        [$meta, $metaErrors] = $this->generateMetaWithRetry($brandName, $existingDescription, $city, $skipValidate, $io);

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
            $this->applyMeta($brand, $meta);
            $this->entityManager->flush();
            $this->entityManager->detach($brand);
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
    ): void {
        // 1. Генерация description (без retry — объём текста не гарантирован ретраем, лучше провалиться явно)
        $description = $this->llmService->generateBrandDescription(
            brandName: $brandName,
            city: $city,
            style: $this->getStyleContext($brand),
        );

        if (!$skipValidate) {
            $descErrors = $this->validator->validateDescription($description);
            if (!empty($descErrors)) {
                $io->warning(sprintf('Валидация description не прошла для "%s": %s', $brandName, implode(', ', $descErrors)));
                $this->validationFailed++;
                return;
            }
        }

        // 2. Генерация meta на основе только что созданного description
        [$meta, $metaErrors] = $this->generateMetaWithRetry($brandName, $description, $city, $skipValidate, $io);

        if (!$skipValidate && !empty($metaErrors)) {
            $io->warning(sprintf('Валидация meta не прошла для "%s": %s', $brandName, implode(', ', $metaErrors)));
            $this->validationFailed++;
            return;
        }

        if (!$dryRun) {
            $brand->setDescription($description);
            $this->applyMeta($brand, $meta);
            $this->entityManager->flush();
            $this->entityManager->detach($brand);
        }

        $this->processed++;
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
    ): array {
        $meta   = [];
        $errors = [];

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $meta = $this->llmService->generateMetaFromExistingDescription(
                brandName: $brandName,
                existingDescription: $description,
                city: $city,
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
        $brand->setMetaTitle(mb_substr($meta['title'] ?? '', 0, 60) ?: null);
        $brand->setMetaDescription(mb_substr($meta['description'] ?? '', 0, 155) ?: null);
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

    private function printResults(SymfonyStyle $io, bool $metaOnly): void
    {
        $io->newLine();

        $rows = [];

        if (!$metaOnly) {
            $rows[] = ['Сгенерировано (description + meta)', $this->processed];
            $rows[] = ['Не прошло валидацию',               $this->validationFailed];
        }

        $rows[] = ['Обновлено только meta', $this->metaGenerated];
        $rows[] = ['Ошибок LLM',           $this->failed];

        $io->table(['Результат', 'Количество'], $rows);
    }
}
