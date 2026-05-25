<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductVariant;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Импорт товаров из XLSX / CSV
 *
 * Формат: одна строка = один вариант.
 * Строки с одинаковым "Название товара" группируются в один Product.
 * При повторном импорте: если SKU уже существует — вариант обновляется.
 *
 * Совместимость с WB:
 *   WB "Наименование"      → col 0 (Название товара)
 *   WB "Артикул продавца"  → col 5 (Артикул SKU)
 *   WB "Размер"            → col 6
 *   WB "Цвет"              → col 7
 *   WB "Цена"              → col 9 (Цена)
 */
class ProductImportService
{
    // Максимум строк данных за один импорт
    private const MAX_ROWS = 1000;

    // Индексы колонок (0-based) — соответствуют шаблону
    private const COL_TITLE        = 0;
    private const COL_CATEGORY     = 1;
    private const COL_GENDER       = 2;
    private const COL_ANONS        = 3;
    private const COL_DESCRIPTION  = 4;
    private const COL_SKU          = 5;
    private const COL_SIZE         = 6;
    private const COL_COLOR        = 7;
    private const COL_COLOR_HEX    = 8;
    private const COL_PRICE        = 9;
    private const COL_COMPARE      = 10;
    private const COL_STOCK        = 11;
    private const COL_WEIGHT       = 12;
    private const COL_MATERIAL     = 13;
    private const COL_COMPOSITION  = 14;
    private const COL_CARE         = 15;
    private const COL_COUNTRY      = 16;
    private const COL_MANUFACTURER = 17;
    private const COL_PHOTO_URLS   = 18;

    private array $errors   = [];
    private int   $created  = 0;
    private int   $updated  = 0;
    private int   $skipped  = 0;

    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly ProductCategoryRepository $categoryRepo,
        private readonly ProductRepository         $productRepo,
        private readonly SluggerInterface          $slugger,
        private readonly ImageDownloaderService    $imageDownloader,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    public function import(UploadedFile $file, Brand $brand): array
    {
        $this->errors  = [];
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;

        $ext  = strtolower($file->getClientOriginalExtension());
        $rows = match (true) {
            $ext === 'csv'             => $this->parseCsv($file->getPathname()),
            in_array($ext, ['xlsx', 'xls'], true) => $this->parseXlsx($file->getPathname()),
            default                    => null,
        };

        if ($rows === null) {
            $this->errors[] = "Неподдерживаемый формат файла «{$file->getClientOriginalName()}». Загрузите .xlsx или .csv";
            return $this->result();
        }

        if (empty($rows)) {
            $this->errors[] = 'Файл не содержит данных';
            return $this->result();
        }

        if (count($rows) > self::MAX_ROWS) {
            $this->errors[] = sprintf('Файл содержит %d строк — максимум %d за один импорт', count($rows), self::MAX_ROWS);
            return $this->result();
        }

        $this->processRows($rows, $brand);
        $this->em->flush();

        return $this->result();
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->errors[] = 'Не удалось открыть CSV-файл';
            return [];
        }

        // Detect BOM and delimiter
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }

        $rows      = [];
        $headerRow = null;
        $lineNum   = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            // Auto-detect delimiter on first real line
            $delimiter = ';';
            if ($lineNum === 1) {
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
            }

            $cells = str_getcsv($line, $delimiter, '"');

            if ($headerRow === null) {
                $headerRow = $cells;
                continue; // skip header
            }

            // Skip "note" row (second row in our template is italic notes)
            if ($lineNum === 2 && $this->looksLikeNoteRow($cells)) {
                continue;
            }

            $rows[] = $cells;
        }

        fclose($handle);
        return $rows;
    }

    private function parseXlsx(string $path): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            $this->errors[] = 'PhpSpreadsheet не установлен. Выполните: composer require phpoffice/phpspreadsheet';
            return [];
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } catch (\Throwable $e) {
            $this->errors[] = 'Ошибка чтения XLSX: ' . $e->getMessage();
            return [];
        }

        // Try "Товары" sheet first, then active sheet
        $sheetNames = $spreadsheet->getSheetNames();
        $sheet = in_array('Товары', $sheetNames, true)
            ? $spreadsheet->getSheetByName('Товары')
            : $spreadsheet->getActiveSheet();

        $rows      = [];
        $headerRow = null;
        $firstRow  = true;

        foreach ($sheet->getRowIterator() as $rowIdx => $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string) $cell->getFormattedValue());
            }

            // Remove trailing empty cells
            while (!empty($cells) && end($cells) === '') {
                array_pop($cells);
            }

            if (empty($cells) || implode('', $cells) === '') {
                continue; // blank row
            }

            if ($firstRow) {
                $firstRow  = false;
                $headerRow = $cells;
                continue; // skip header
            }

            // Skip "note" row (row 2 in our template)
            if ($rowIdx === 2 && $this->looksLikeNoteRow($cells)) {
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** Detect the italicized note row in our template */
    private function looksLikeNoteRow(array $cells): bool
    {
        $first = $cells[0] ?? '';
        return str_contains($first, 'Обязательно') || str_contains($first, 'названием')
            || str_contains($first, 'одинаковым');
    }

    // ── Processing ────────────────────────────────────────────────────────────

    private function processRows(array $rows, Brand $brand): void
    {
        /** @var Product[] $productCache title → Product */
        $productCache = [];

        /** @var array<string, ProductVariant> $skuCache sku → ProductVariant */
        $skuCache = [];

        // Pre-load existing variants for this brand by SKU to enable updates
        $existing = $this->em->createQuery(
            'SELECT v, p FROM App\Entity\ProductVariant v
             JOIN v.product p
             WHERE p.brand = :brand AND v.sku IS NOT NULL'
        )->setParameter('brand', $brand)->getResult();

        foreach ($existing as $variant) {
            if ($variant instanceof ProductVariant && $variant->getSku()) {
                $skuCache[$variant->getSku()] = $variant;
            }
        }

        foreach ($rows as $rowNum => $row) {
            $lineLabel = "Строка " . ($rowNum + 3); // +3 because header + note = row 1&2

            $title = trim($this->cell($row, self::COL_TITLE));
            $sku   = trim($this->cell($row, self::COL_SKU));
            $price = $this->parsePrice($this->cell($row, self::COL_PRICE));

            // ── Validation ────────────────────────────────────────────────────
            if ($title === '') {
                $this->errors[] = "$lineLabel: «Название товара» пустое — строка пропущена";
                $this->skipped++;
                continue;
            }

            if ($sku === '') {
                $this->errors[] = "$lineLabel: «Артикул (SKU)» пустой — строка пропущена";
                $this->skipped++;
                continue;
            }

            if ($price === null || $price <= 0) {
                $this->errors[] = "$lineLabel: «Цена» некорректная («{$this->cell($row, self::COL_PRICE)}») — строка пропущена";
                $this->skipped++;
                continue;
            }

            // ── Product (create or reuse) ─────────────────────────────────────
            $product = $productCache[$title] ?? null;
            if ($product === null) {
                // Check DB for existing product with same title in this brand
                $product = $this->productRepo->findOneBy(['brand' => $brand, 'title' => $title]);

                if ($product === null) {
                    $product = new Product();
                    $product->setBrand($brand);
                    $product->setTitle($title);
                    $product->setSlug($this->generateUniqueSlug($title));
                    $this->em->persist($product);
                }

                // Update product-level fields (only from first row of this product)
                $categorySlug = trim($this->cell($row, self::COL_CATEGORY));
                if ($categorySlug !== '') {
                    $cat = $this->categoryRepo->findOneBy(['slug' => $categorySlug]);
                    if ($cat) {
                        $product->setCategory($cat);
                    }
                }

                $gender = strtolower(trim($this->cell($row, self::COL_GENDER)));
                if (in_array($gender, ['women', 'men', 'unisex'], true)) {
                    $product->setGender($gender);
                }

                $anons = trim($this->cell($row, self::COL_ANONS));
                if ($anons !== '' && !$product->getAnons()) {
                    $product->setAnons(mb_substr($anons, 0, 500));
                }

                $desc = trim($this->cell($row, self::COL_DESCRIPTION));
                if ($desc !== '' && !$product->getDescription()) {
                    $product->setDescription($desc);
                }

                // Use price from first variant as product base price
                $product->setPrice($price);

                // Characteristics
                $material = trim($this->cell($row, self::COL_MATERIAL));
                if ($material !== '' && !$product->getMaterial()) {
                    $product->setMaterial($material);
                }

                $composition = trim($this->cell($row, self::COL_COMPOSITION));
                if ($composition !== '' && !$product->getComposition()) {
                    $product->setComposition($composition);
                }

                $care = trim($this->cell($row, self::COL_CARE));
                if ($care !== '' && !$product->getCareInstructions()) {
                    $product->setCareInstructions($care);
                }

                $country = trim($this->cell($row, self::COL_COUNTRY));
                if ($country !== '' && !$product->getCountryOfOrigin()) {
                    $product->setCountryOfOrigin($country);
                }

                $manufacturer = trim($this->cell($row, self::COL_MANUFACTURER));
                if ($manufacturer !== '' && !$product->getManufacturer()) {
                    $product->setManufacturer($manufacturer);
                }

                // Photo URLs (only from first row of this product)
                if ($product->getProductImages()->isEmpty()) {
                    $photoUrls = trim($this->cell($row, self::COL_PHOTO_URLS));
                    if ($photoUrls !== '') {
                        $urls = array_filter(array_map('trim', explode('|', $photoUrls)));
                        $sort = 0;
                        foreach ($urls as $url) {
                            $filename = $this->imageDownloader->download($url);
                            if ($filename === null) {
                                continue;
                            }
                            $image = new ProductImage();
                            $image->setImage($filename);
                            $image->setPreview($filename);
                            $image->setIsMain($sort === 0);
                            $image->setSort($sort);
                            $product->addProductImage($image);
                            $this->em->persist($image);
                            $sort++;
                            if ($sort >= 10) {
                                break;
                            }
                        }
                    }
                }

                $productCache[$title] = $product;
            }

            // ── Variant (create or update by SKU) ────────────────────────────
            $isNew   = false;
            $variant = $skuCache[$sku] ?? null;

            if ($variant === null) {
                $variant = new ProductVariant();
                $variant->setProduct($product);
                $variant->setSku($sku);
                $this->em->persist($variant);
                $skuCache[$sku] = $variant;
                $isNew          = true;
            }

            $variant->setPrice((string) $price);

            $compare = $this->parsePrice($this->cell($row, self::COL_COMPARE));
            $variant->setComparePrice($compare !== null ? (string) $compare : null);

            $size = trim($this->cell($row, self::COL_SIZE));
            if ($size !== '') {
                $variant->setSize($size);
            }

            $color = trim($this->cell($row, self::COL_COLOR));
            if ($color !== '') {
                $variant->setColor($color);
            }

            $hex = trim($this->cell($row, self::COL_COLOR_HEX));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
                $variant->setColorHex($hex);
            }

            $stock = (int) $this->cell($row, self::COL_STOCK);
            $variant->setStockQty(max(0, $stock));

            $weight = $this->cell($row, self::COL_WEIGHT);
            if (is_numeric($weight) && (int) $weight > 0) {
                $variant->setWeight((int) $weight);
            }

            if ($isNew) {
                $this->created++;
            } else {
                $this->updated++;
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cell(array $row, int $index): string
    {
        return isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    private function parsePrice(string $value): ?float
    {
        $cleaned = preg_replace('/[^0-9.,]/', '', $value);
        $cleaned = str_replace(',', '.', $cleaned);
        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }
        return (float) $cleaned;
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = strtolower((string) $this->slugger->slug($title));
        $slug = $base;
        $i    = 1;

        while ($this->productRepo->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function result(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors'  => $this->errors,
            'total'   => $this->created + $this->updated + $this->skipped,
        ];
    }
}
