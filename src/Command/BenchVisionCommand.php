<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Service\LlmService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Twig\ProductImageExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Бенч ollama vision-модели на распознавании категории/цвета одежды по фото ТОВАРОВ
 * КАТАЛОГА (публичные данные, не фото пользователей гардероба). Промпт и парсинг ответа —
 * ТЕ ЖЕ, что в проде: WardrobeAiService::analyzePhotoWithLocalModel() переиспользует
 * приватные photoPrompt()/parsePhotoResponse() из suggestFromPhoto()/analyzePhoto(), но
 * без кеша/согласия/дневного лимита/usage-лога — они не нужны разовому сравнению моделей.
 *
 * Эталон: product_category.title (категория) + цвет первого варианта товара с непустым
 * color (product_variant.color). Набор детерминирован — ORDER BY product.id, одни и те
 * же товары для всех моделей.
 *
 *   php bin/console app:bench:vision gemma4:26b
 *   php bin/console app:bench:vision gemma4:31b-it-qat-mmap --limit=10
 *
 * Пишет поштучный лог var/bench/vision-<model>.jsonl (перезаписывается на каждый запуск)
 * и дописывает сводную строку в docs/model-vision-bench.md.
 */
#[AsCommand(name: 'app:bench:vision', description: 'Бенч ollama vision-модели на распознавании категории/цвета товаров каталога')]
class BenchVisionCommand extends Command
{
    private const DEFAULT_DOC = 'docs/model-vision-bench.md';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WardrobeAiService $wardrobeAi,
        private readonly LlmService $llm,
        private readonly ProductImageExtension $productImages,
        #[Autowire('%kernel.project_dir%/public_html')]
        private readonly string $publicDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('model', InputArgument::REQUIRED, 'ollama vision-тег (gemma4:26b, gemma4:31b-it-qat-mmap)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'сколько товаров каталога взять', '30')
            ->addOption('doc', null, InputOption::VALUE_REQUIRED, 'markdown-документ для сводной строки', self::DEFAULT_DOC);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model = (string) $input->getArgument('model');
        $limit = max(1, (int) $input->getOption('limit'));
        $doc   = (string) $input->getOption('doc');

        $products = $this->eligibleProducts($limit);
        if ($products === []) {
            $output->writeln('<error>Нет подходящих товаров (нужны: активный статус, категория, фото на диске, цвет хотя бы одного варианта)</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Товаров: %d (id: %s), модель: %s', count($products), implode(',', array_column($products, 'id')), $model));

        // Прогрев: грузим модель в VRAM текстовым запросом (тот же тег, generate() использует
        // 600с таймаут-пол), иначе первый vision-запрос рискует упереться в жёсткий 120с
        // таймаут LlmService::generateVisionLocal() при холодной загрузке (см. docs/model-ab-bench.md).
        try {
            $this->llm->generate('ok', model: $model, local: true, think: false);
        } catch (\Throwable $e) {
            $output->writeln('  warm-up err: ' . $e->getMessage());
        }

        $rows = [];
        foreach ($products as $p) {
            $start = microtime(true);
            $validJson = false;
            $gotCategory = null;
            $gotColor = null;
            try {
                $res = $this->wardrobeAi->analyzePhotoWithLocalModel($p['path'], $model);
                $validJson = (bool) ($res['ok'] ?? false);
                $gotCategory = $res['fields']['category'] ?? null;
                $gotColor = $res['fields']['colorName'] ?? null;
            } catch (\Throwable $e) {
                $output->writeln("  ERR #{$p['id']}: " . $e->getMessage());
            }
            $seconds = microtime(true) - $start;

            $categoryMatch = $validJson && self::matches($gotCategory, $p['category']);
            $colorMatch = $validJson && self::matches($gotColor, $p['color']);

            $rows[] = [
                'product_id' => $p['id'],
                'expected_category' => $p['category'],
                'expected_color' => $p['color'],
                'got_category' => $gotCategory,
                'got_color' => $gotColor,
                'valid_json' => $validJson,
                'category_match' => $categoryMatch,
                'color_match' => $colorMatch,
                'seconds' => round($seconds, 2),
            ];

            $output->writeln(sprintf(
                '  #%d %s · категория %s · цвет %s · %.1fs',
                $p['id'],
                $validJson ? 'ok' : 'invalid-json',
                $categoryMatch ? 'match' : 'miss',
                $colorMatch ? 'match' : 'miss',
                $seconds,
            ));
        }

        $this->writeJsonl($model, $rows);
        $this->appendSummaryRow($doc, $model, $rows);

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id:int,path:string,category:?string,color:?string}>
     */
    private function eligibleProducts(int $limit): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT p.id FROM product p
             JOIN product_category pc ON pc.id = p.category_id
             WHERE p.status = 'active' AND pc.title IS NOT NULL
               AND EXISTS (SELECT 1 FROM product_image pi WHERE pi.product_id = p.id)
               AND EXISTS (SELECT 1 FROM product_variant pv WHERE pv.product_id = p.id AND pv.color IS NOT NULL AND pv.color <> '')
             ORDER BY p.id ASC",
        );

        $out = [];
        foreach ($ids as $id) {
            if (count($out) >= $limit) {
                break;
            }
            $product = $this->em->find(Product::class, (int) $id);
            $image = $product?->getMainImage();
            $uri = $image !== null ? $this->productImages->productImageUrl($image, 'image') : null;
            $path = $uri !== null ? $this->publicDir . $uri : null;
            if ($path === null || !is_file($path)) {
                continue; // ссылка есть в БД, файла на диске нет (см. product-image-two-storage-layouts)
            }

            $color = null;
            foreach ($product->getVariants() as $variant) {
                $c = $variant->getColor();
                if ($c !== null && trim($c) !== '') {
                    $color = $c;
                    break;
                }
            }

            $out[] = [
                'id' => (int) $product->getId(),
                'path' => $path,
                'category' => $product->getCategory()?->getTitle(),
                'color' => $color,
            ];
        }

        return $out;
    }

    /** Простая нормализация: регистр/ё/небуквенные символы; совпадение — точное, вхождение или общий 4-символьный префикс. */
    public static function matches(?string $actual, ?string $expected): bool
    {
        if ($actual === null || $expected === null) {
            return false;
        }
        $norm = static function (string $s): string {
            $s = str_replace('ё', 'е', mb_strtolower(trim($s)));

            return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;
        };
        $a = $norm($actual);
        $e = $norm($expected);
        if ($a === '' || $e === '') {
            return false;
        }
        if ($a === $e || str_contains($a, $e) || str_contains($e, $a)) {
            return true;
        }

        return mb_strlen($a) >= 4 && mb_strlen($e) >= 4 && mb_substr($a, 0, 4) === mb_substr($e, 0, 4);
    }

    private function writeJsonl(string $model, array $rows): void
    {
        $dir = $this->projectDir . '/var/bench';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/vision-' . $this->sanitizeModel($model) . '.jsonl';

        $lines = array_map(static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $rows);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** Дописывает строку в существующий docs/model-vision-bench.md (шапка+таблица уже в репо, как у app:bench:models). */
    private function appendSummaryRow(string $doc, string $model, array $rows): void
    {
        $n = count($rows);
        $rate = static fn (string $key): float => 100 * array_sum(array_column($rows, $key)) / $n;

        $line = sprintf(
            "| %s | %d | %.0f%% | %.0f%% | %.0f%% | %.1f |\n",
            $model,
            $n,
            $rate('valid_json'),
            $rate('category_match'),
            $rate('color_match'),
            array_sum(array_column($rows, 'seconds')) / $n,
        );

        $path = str_starts_with($doc, '/') ? $doc : $this->projectDir . '/' . $doc;
        file_put_contents($path, $line, FILE_APPEND);
    }

    private function sanitizeModel(string $model): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $model) ?? $model;
    }
}
