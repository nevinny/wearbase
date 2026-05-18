<?php

namespace App\Command;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use App\Service\ContentValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:brand:check-content',
    description: 'Проверка контента брендов на качество',
)]
class CheckBrandContentCommand extends Command
{
    private int $total = 0;
    private int $valid = 0;
    private int $invalid = 0;
    private array $issues = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Лимит брендов', 50)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Тип проверки: description, meta, all', 'all')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Экспорт в JSON файл')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $type = $input->getOption('type');
        $export = $input->getOption('export');

        $io->title('Проверка контента брендов');

        $repo = $this->entityManager->getRepository(Brand::class);
        $brands = $repo->findBy([], ['created_at' => 'DESC'], $limit);

        $this->total = count($brands);

        foreach ($brands as $brand) {
            $this->checkBrand($brand, $type);
        }

        $io->newLine();
        $io->table(
            ['Метрика', 'Количество'],
            [
                ['Всего проверено', $this->total],
                ['Валидных', $this->valid],
                ['С проблемами', $this->invalid],
            ]
        );

        if ($type === 'all' || $type === 'description') {
            $descIssues = array_filter($this->issues, fn($i) => $i['type'] === 'description');
            if (!empty($descIssues)) {
                $io->section('Проблемы с описаниями:');
                $io->table(
                    ['Бренд', 'Проблема'],
                    array_map(fn($i) => [$i['brand'], $i['issue']], array_values($descIssues))
                );
            }
        }

        if ($type === 'all' || $type === 'meta') {
            $metaIssues = array_filter($this->issues, fn($i) => $i['type'] === 'meta');
            if (!empty($metaIssues)) {
                $io->section('Проблемы с meta:');
                $io->table(
                    ['Бренд', 'Проблема'],
                    array_map(fn($i) => [$i['brand'], $i['issue']], array_values($metaIssues))
                );
            }
        }

        if ($export) {
            file_put_contents($export, json_encode([
                'total' => $this->total,
                'valid' => $this->valid,
                'invalid' => $this->invalid,
                'issues' => $this->issues,
                'ai_phrases' => $this->validator->getAiPhrases(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $io->success("Экспорт сохранён: {$export}");
        }

        return Command::SUCCESS;
    }

    private function checkBrand(Brand $brand, string $type): void
    {
        $brandName = $brand->getTitle() ?? 'Unknown';
        $hasIssue = false;

        if ($type === 'all' || $type === 'description') {
            $description = trim($brand->getDescription() ?? '');
            if ($description !== '') {
                $errors = $this->validator->validateDescription($description);
                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $this->issues[] = [
                            'id' => $brand->getId(),
                            'brand' => $brandName,
                            'type' => 'description',
                            'issue' => $error,
                        ];
                    }
                    $hasIssue = true;
                }
            } else {
                $this->issues[] = [
                    'id' => $brand->getId(),
                    'brand' => $brandName,
                    'type' => 'description',
                    'issue' => 'Пустое описание',
                ];
                $hasIssue = true;
            }
        }

        if ($type === 'all' || $type === 'meta') {
            $meta = [
                'title' => $brand->getMetaTitle(),
                'description' => $brand->getMetaDescription(),
                'keywords' => $brand->getMetaKeywords(),
            ];

            $errors = $this->validator->validateMeta($meta);
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->issues[] = [
                        'id' => $brand->getId(),
                        'brand' => $brandName,
                        'type' => 'meta',
                        'issue' => $error,
                    ];
                }
                $hasIssue = true;
            }

            if (empty($meta['description'])) {
                $this->issues[] = [
                    'id' => $brand->getId(),
                    'brand' => $brandName,
                    'type' => 'meta',
                    'issue' => 'Отсутствует meta_description',
                ];
                $hasIssue = true;
            }
        }

        if ($hasIssue) {
            $this->invalid++;
        } else {
            $this->valid++;
        }
    }
}