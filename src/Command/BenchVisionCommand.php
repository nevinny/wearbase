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
 * --manifest=path.jsonl: набор берётся не из каталога, а из готового JSONL (строки
 * {"id":..,"path":"<путь>","category":"..","color":"..","ai_prefilled":true|false|null}) —
 * используется для эталона на реальных вещах гардероба (личные фото, путь и manifest
 * готовятся отдельно, вне этой команды, и живут только на Mac вне git). ai_prefilled
 * (вещь была принята из AI-черновика с подсказкой) прокидывается в лог и разбивает
 * сводку на когорты — так виден возможный уклон эталона к модели, которая его подсказала.
 *
 * Метрика двухуровневая — точное совпадение (matches()) плюс более мягкое групповое:
 * категория сводится к одной из ~6 групп (верх/низ/платья/верхняя одежда/обувь/
 * аксессуары), цвет — к одному из ~12 семейств (colorFamilies()); эталон-строка может
 * перечислять несколько цветов через ";" — групповое совпадение засчитывается, если
 * семейства пересекаются хотя бы по одному значению.
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'сколько товаров/вещей взять', '30')
            ->addOption('doc', null, InputOption::VALUE_REQUIRED, 'markdown-документ для сводной строки', self::DEFAULT_DOC)
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'JSONL с готовым набором (id,path,category,color,ai_prefilled) вместо товаров каталога');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model    = (string) $input->getArgument('model');
        $limit    = max(1, (int) $input->getOption('limit'));
        $doc      = (string) $input->getOption('doc');
        $manifest = (string) $input->getOption('manifest');

        $products = $manifest !== '' ? $this->manifestItems($manifest, $limit) : $this->eligibleProducts($limit);
        if ($products === []) {
            $output->writeln($manifest !== ''
                ? '<error>Manifest пуст или ни один путь к фото не найден на диске</error>'
                : '<error>Нет подходящих товаров (нужны: активный статус, категория, фото на диске, цвет хотя бы одного варианта)</error>');

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
            $categoryGroupMatch = $validJson && self::categoryGroupMatches($gotCategory, $p['category']);
            $colorFamilyMatch = $validJson && self::colorFamilyMatches($gotColor, $p['color']);

            $rows[] = [
                'product_id' => $p['id'],
                'expected_category' => $p['category'],
                'expected_color' => $p['color'],
                'got_category' => $gotCategory,
                'got_color' => $gotColor,
                'valid_json' => $validJson,
                'category_match' => $categoryMatch,
                'category_group_match' => $categoryGroupMatch,
                'color_match' => $colorMatch,
                'color_family_match' => $colorFamilyMatch,
                'seconds' => round($seconds, 2),
                'ai_prefilled' => $p['ai_prefilled'] ?? null,
            ];

            $output->writeln(sprintf(
                '  #%d %s · категория %s(%s) · цвет %s(%s) · %.1fs',
                $p['id'],
                $validJson ? 'ok' : 'invalid-json',
                $categoryMatch ? 'match' : 'miss',
                $categoryGroupMatch ? 'group' : '-',
                $colorMatch ? 'match' : 'miss',
                $colorFamilyMatch ? 'family' : '-',
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

    /**
     * Набор из готового JSONL (см. докблок класса) вместо запроса к каталогу — для
     * эталона на реальных вещах гардероба. Строки без читаемого файла на диске
     * пропускаются молча (manifest готовится заранее и может отставать от rsync).
     *
     * @return list<array{id:int,path:string,category:?string,color:?string,ai_prefilled:?bool}>
     */
    public static function manifestItems(string $manifestPath, int $limit): array
    {
        $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $out = [];
        foreach ($lines as $line) {
            if (count($out) >= $limit) {
                break;
            }
            $row = json_decode($line, true);
            $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'path' => $path,
                'category' => $row['category'] ?? null,
                'color' => $row['color'] ?? null,
                'ai_prefilled' => $row['ai_prefilled'] ?? null,
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

    /**
     * ~12 базовых семейств цвета (ключевые слова — грубый, но простой классификатор:
     * оттенки/уточнения вроде «мокрый асфальт» или «пыльная роза» ловятся ключевыми
     * словами конкретного семейства). Строка может попасть в НЕСКОЛЬКО семейств
     * («серо-голубой» → серый + синий) — это осознанно, см. colorFamilyMatches().
     */
    private const COLOR_FAMILY_KEYWORDS = [
        'черный' => ['черн', 'графит', 'уголь', 'антрацит'],
        'белый' => ['бел', 'молочн', 'айвори', 'слонов'],
        'серый' => ['сер', 'асфальт', 'дымч'],
        'бежевый_коричневый' => ['беж', 'корич', 'шоколад', 'кофе', 'какао', 'песоч', 'карамель', 'хаки', 'терракот', 'капуч', 'леопард', 'нюд', 'телесн'],
        'красный_бордовый' => ['красн', 'бордов', 'вишн', 'бургунд', 'малинов', 'винн'],
        'розовый' => ['розов', 'роза', 'фуксия'],
        'оранжевый' => ['оранж', 'морков'],
        'желтый' => ['желт', 'горчич', 'лимонн'],
        'зеленый_оливковый' => ['зелен', 'олив', 'салатов', 'мятн', 'изумруд'],
        'голубой_синий' => ['голуб', 'син', 'бирюз', 'джинс', 'индиго'],
        'фиолетовый' => ['фиолет', 'сирен', 'лавенд', 'баклажан'],
        'металлик' => ['серебр', 'золот', 'металл', 'хром'],
    ];

    /** @return list<string> семейства, найденные в строке (может быть несколько, может быть пусто) */
    public static function colorFamilies(string $raw): array
    {
        $lower = str_replace('ё', 'е', mb_strtolower($raw));
        $found = [];
        foreach (self::COLOR_FAMILY_KEYWORDS as $family => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $found[$family] = true;
                    break;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Групповое совпадение цвета: $expected может перечислять несколько цветов через
     * ";"/"," (напр. «розовый; салатовый; белый») — совпадение, если семейства
     * пересекаются хотя бы по одному значению.
     */
    public static function colorFamilyMatches(?string $actual, ?string $expected): bool
    {
        if ($actual === null || $expected === null) {
            return false;
        }
        $expectedFamilies = [];
        foreach (preg_split('/[;,]/u', $expected) ?: [] as $part) {
            $expectedFamilies = array_merge($expectedFamilies, self::colorFamilies($part));
        }

        return array_intersect($expectedFamilies, self::colorFamilies($actual)) !== [];
    }

    /** Грубая группировка категории одежды/обуви/аксессуаров ключевыми словами. */
    private const CATEGORY_GROUP_KEYWORDS = [
        'верх' => ['майка', 'футболк', 'топ', 'лонгслив', 'водолазк', 'рубашк', 'блуз', 'свитер', 'свитшот', 'худи', 'кофт', 'поло', 'джемпер'],
        'низ' => ['джинс', 'брюк', 'штан', 'шорт', 'юбк', 'легинс', 'джоггер'],
        'платья' => ['плать', 'сарафан'],
        'верхняя одежда' => ['куртк', 'пальто', 'косух', 'пуховик', 'плащ', 'жилет', 'ветровк', 'бомбер', 'шуб', 'парк'],
        'обувь' => ['туфл', 'кроссовк', 'ботин', 'ботильон', 'сапог', 'сандал', 'кед', 'лофер', 'мокасин', 'обув'],
        'аксессуары' => ['ремен', 'ремн', 'шапк', 'шарф', 'перчатк', 'сумк', 'очк', 'украшен', 'носк', 'платок', 'аксессуар'],
    ];

    public static function categoryGroup(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $lower = str_replace('ё', 'е', mb_strtolower($raw));
        foreach (self::CATEGORY_GROUP_KEYWORDS as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $group;
                }
            }
        }

        return null;
    }

    public static function categoryGroupMatches(?string $actual, ?string $expected): bool
    {
        $a = self::categoryGroup($actual);
        $e = self::categoryGroup($expected);

        return $a !== null && $e !== null && $a === $e;
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

    /**
     * Дописывает строку(и) в существующий docs/model-vision-bench.md (шапка+таблица уже
     * в репо, как у app:bench:models). Если среди строк реально встречается больше одного
     * значения ai_prefilled (manifest-режим на вещах гардероба — true/false/null, null —
     * своя когорта, не «неизвестно=false») — вдобавок к общей строке дописывает разбивку
     * по когортам, чтобы был виден возможный уклон эталона к модели, подсказавшей его.
     * Каталожный режим (ai_prefilled всегда null) разбивку не получает — она не несёт
     * информации, когда когорта одна.
     */
    private function appendSummaryRow(string $doc, string $model, array $rows): void
    {
        $lines = $this->summaryLine($model, $rows);

        $cohorts = array_unique(array_map(static fn (array $r): string => json_encode($r['ai_prefilled'] ?? null), $rows));
        if (count($cohorts) > 1) {
            foreach ([true, false, null] as $flag) {
                $subset = array_values(array_filter($rows, static fn (array $r): bool => ($r['ai_prefilled'] ?? null) === $flag));
                if ($subset !== []) {
                    $label = $flag === null ? 'null' : ($flag ? 'true' : 'false');
                    $lines .= $this->summaryLine($model . " [ai_prefilled={$label}]", $subset);
                }
            }
        }

        $path = str_starts_with($doc, '/') ? $doc : $this->projectDir . '/' . $doc;
        file_put_contents($path, $lines, FILE_APPEND);
    }

    private function summaryLine(string $label, array $rows): string
    {
        $n = count($rows);
        $rate = static fn (string $key): float => 100 * array_sum(array_column($rows, $key)) / $n;

        return sprintf(
            "| %s | %d | %.0f%% | %.0f%% | %.0f%% | %.0f%% | %.0f%% | %.1f |\n",
            $label,
            $n,
            $rate('valid_json'),
            $rate('category_match'),
            $rate('category_group_match'),
            $rate('color_match'),
            $rate('color_family_match'),
            array_sum(array_column($rows, 'seconds')) / $n,
        );
    }

    private function sanitizeModel(string $model): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $model) ?? $model;
    }
}
