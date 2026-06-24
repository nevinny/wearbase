<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SEO-рейтинги брендов по поисковому спросу (Wordstat, `brand_keyword.monthly_shows`):
 *  1) бренд → город (сумма показов по всем фразам бренда);
 *  2) матрица стиль × город → топ брендов (бренд с N стилями входит в N ячеек,
 *     объём считается по бренду в подзапросе ДО джойна со стилями — без задвоения).
 *
 * Выгружает CSV (полные данные) + MD (читаемая сводка) в `--out` (default var/seo).
 * Питает идеи для листиклов (app:seo:listicle): какие бренды/города/стили в спросе.
 *
 * ⚠️ Бренды со словарными именами (ТВОЕ, ТАЙНА, МЕЧ…) раздуты — Wordstat считает
 * запросы самого слова, а не бренда (омоним-шум). Топ по «сырому» объёму ≠ спрос
 * на бренд; `--min-kw` частично режет хвост однофразовых брендов.
 *
 *   php bin/console app:seo:ranking                              # полные отчёты в файлы
 *   php bin/console app:seo:ranking --out=var/seo --min-kw=3
 *   php bin/console app:seo:ranking --style=streetwear --city=казань --top=5   # срез в консоль
 */
#[AsCommand(
    name: 'app:seo:ranking',
    description: 'SEO-рейтинги брендов по спросу: бренд→город + матрица стиль×город→топ брендов',
)]
class SeoRankingCommand extends Command
{
    private const DEFAULT_OUT   = 'var/seo';
    private const TOP_BRANDS_MD = 25; // строк в общем топе брендов (MD)
    private const TOP_CITIES_MD = 20; // строк в топе городов (MD)
    private const TOP_PER_CELL  = 3;  // брендов в ячейке стиль×город (MD)

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('out',    null, InputOption::VALUE_REQUIRED, 'Папка для CSV/MD', self::DEFAULT_OUT)
            ->addOption('min-kw', null, InputOption::VALUE_REQUIRED, 'Минимум ключевых фраз у бренда (режет омоним-хвост)', '1')
            ->addOption('style',  null, InputOption::VALUE_REQUIRED, 'Срез: slug или название стиля (вывод в консоль, без файлов)')
            ->addOption('city',   null, InputOption::VALUE_REQUIRED, 'Срез: город (подстрока, регистр не важен)')
            ->addOption('top',    null, InputOption::VALUE_REQUIRED, 'Срез: сколько брендов показать', '5')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $outDir = (string) $input->getOption('out');
        $minKw  = max(1, (int) $input->getOption('min-kw'));

        // Режим среза: --style и/или --city → один ранжированный список в консоль.
        $style = $input->getOption('style');
        $city  = $input->getOption('city');
        if ($style !== null || $city !== null) {
            return $this->focused($io, $style, $city, max(1, (int) $input->getOption('top')), $minKw);
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $io->error("Не удалось создать папку {$outDir}.");
            return Command::FAILURE;
        }

        $io->title('SEO-рейтинги брендов по поисковому спросу');
        $io->text(sprintf('Фильтр: активные бренды с городом, ≥%d ключевых фраз. Источник: Wordstat (region 225).', $minKw));

        $brands = $this->fetchBrandCity($minKw);
        $styles = $this->fetchBrandStyle($minKw);
        if ($brands === []) {
            $io->warning('Нет данных (нет брендов с городом и ключевиками).');
            return Command::FAILURE;
        }

        $cityPaths  = $this->buildCityReport($brands, $outDir);
        $matrixPaths = $this->buildStyleMatrix($styles, $outDir);

        $this->printSummary($io, $brands, $styles);

        $io->success('Готово. Файлы:');
        $io->listing(array_merge($cityPaths, $matrixPaths));

        return Command::SUCCESS;
    }

    /** Срез «топ брендов» по фильтрам стиль/город (вывод в консоль). */
    private function focused(SymfonyStyle $io, ?string $style, ?string $city, int $top, int $minKw): int
    {
        $sql = "SELECT b.title AS brand, b.city AS city, agg.shows_sum, agg.kw_cnt
                FROM (SELECT brand_id, SUM(monthly_shows) AS shows_sum, COUNT(*) AS kw_cnt
                      FROM brand_keyword GROUP BY brand_id HAVING kw_cnt >= :minKw) agg
                JOIN brand b ON b.id = agg.brand_id";
        $where  = ['b.status = :st', "b.city IS NOT NULL", "TRIM(b.city) <> ''"];
        $params = ['minKw' => $minKw, 'st' => Statuses::Active->value, 'top' => $top];
        $types  = ['minKw' => \PDO::PARAM_INT, 'top' => \PDO::PARAM_INT];

        if ($style !== null) {
            $sql .= ' JOIN brand_style_brand bsb ON bsb.brand_id = b.id JOIN brand_style s ON s.id = bsb.brand_style_id';
            $where[] = '(s.slug = :style OR LOWER(s.title) = LOWER(:style))';
            $params['style'] = $style;
        }
        if ($city !== null) {
            $where[] = 'LOWER(b.city) LIKE :city';
            $params['city'] = '%' . mb_strtolower(trim($city), 'UTF-8') . '%';
        }

        $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY agg.shows_sum DESC LIMIT :top';
        $rows = $this->conn()->fetchAllAssociative($sql, $params, $types);

        $io->title(sprintf('Топ-%d брендов%s%s', $top,
            $style !== null ? " · стиль «{$style}»" : '',
            $city !== null ? " · город «{$city}»" : ''));

        if ($rows === []) {
            $io->warning('Ничего не найдено по фильтрам.');
            return Command::SUCCESS;
        }

        $io->table(['#', 'Бренд', 'Город', 'Показов/мес', 'Фраз'],
            array_map(fn($i, $r) => [$i + 1, $r['brand'], $r['city'], $this->fmt((int) $r['shows_sum']), $r['kw_cnt']],
                array_keys($rows), $rows));

        if (count($rows) < $top) {
            $io->note(sprintf('Найдено только %d — для листикла «ТОП-%d» данных в этой ячейке недостаточно.', count($rows), $top));
        }

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------ data

    /** @return array<int,array{brand:string,city:string,shows:int,kw:int}> */
    private function fetchBrandCity(int $minKw): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT b.title AS brand, b.city AS city,
                    SUM(k.monthly_shows) AS shows_sum, COUNT(*) AS kw_cnt
             FROM brand b
             JOIN brand_keyword k ON k.brand_id = b.id
             WHERE b.status = :st AND b.city IS NOT NULL AND TRIM(b.city) <> ''
             GROUP BY b.id, b.title, b.city
             HAVING kw_cnt >= :minKw",
            ['st' => Statuses::Active->value, 'minKw' => $minKw],
        );

        return array_map(fn(array $r) => [
            'brand' => (string) $r['brand'],
            'city'  => $this->normCity((string) $r['city']),
            'shows' => (int) $r['shows_sum'],
            'kw'    => (int) $r['kw_cnt'],
        ], $rows);
    }

    /** @return array<int,array{style:string,city:string,brand:string,shows:int,kw:int}> */
    private function fetchBrandStyle(int $minKw): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT s.title AS style, b.city AS city, b.title AS brand,
                    agg.shows_sum AS shows_sum, agg.kw_cnt AS kw_cnt
             FROM (SELECT brand_id, SUM(monthly_shows) AS shows_sum, COUNT(*) AS kw_cnt
                   FROM brand_keyword GROUP BY brand_id HAVING kw_cnt >= :minKw) agg
             JOIN brand b ON b.id = agg.brand_id
             JOIN brand_style_brand bsb ON bsb.brand_id = b.id
             JOIN brand_style s ON s.id = bsb.brand_style_id
             WHERE b.status = :st AND b.city IS NOT NULL AND TRIM(b.city) <> ''",
            ['st' => Statuses::Active->value, 'minKw' => $minKw],
        );

        return array_map(fn(array $r) => [
            'style' => (string) $r['style'],
            'city'  => $this->normCity((string) $r['city']),
            'brand' => (string) $r['brand'],
            'shows' => (int) $r['shows_sum'],
            'kw'    => (int) $r['kw_cnt'],
        ], $rows);
    }

    // --------------------------------------------------------------- reports

    /**
     * @param array<int,array{brand:string,city:string,shows:int,kw:int}> $brands
     * @return string[] пути созданных файлов
     */
    private function buildCityReport(array $brands, string $outDir): array
    {
        usort($brands, fn($a, $b) => $b['shows'] <=> $a['shows']);
        $cityRank = [];
        foreach ($brands as $i => &$r) {
            $r['rank_overall'] = $i + 1;
            $cityRank[$r['city']] = ($cityRank[$r['city']] ?? 0) + 1;
            $r['rank_city'] = $cityRank[$r['city']];
        }
        unset($r);

        $csv = "{$outDir}/brand-city-ranking.csv";
        $this->writeCsv($csv, ['rank_overall', 'city', 'rank_in_city', 'brand', 'monthly_shows', 'keywords'],
            array_map(fn($r) => [$r['rank_overall'], $r['city'], $r['rank_city'], $r['brand'], $r['shows'], $r['kw']], $brands));

        // города по совокупному спросу
        $cities = $this->aggregateCities($brands);

        $md  = "# Рейтинг брендов в разрезе бренд–город\n\n";
        $md .= $this->sourceNote() . "\n\n";
        $md .= '**Брендов:** ' . count($brands) . ' · **городов:** ' . count($cities) . "\n\n";
        $md .= $this->homonymNote() . "\n\n";

        $md .= "## Топ-" . self::TOP_BRANDS_MD . " брендов по объёму запросов\n\n";
        $md .= "| # | Бренд | Город | Запросов/мес | Фраз |\n|---|---|---|--:|--:|\n";
        foreach (array_slice($brands, 0, self::TOP_BRANDS_MD) as $r) {
            $md .= "| {$r['rank_overall']} | {$r['brand']} | {$r['city']} | " . $this->fmt($r['shows']) . " | {$r['kw']} |\n";
        }

        $md .= "\n## Города по совокупному спросу (топ-" . self::TOP_CITIES_MD . ")\n\n";
        $md .= "| # | Город | Брендов | Σ запросов/мес | Лидер города |\n|---|---|--:|--:|---|\n";
        foreach (array_slice($cities, 0, self::TOP_CITIES_MD) as $i => $c) {
            $md .= '| ' . ($i + 1) . " | {$c['city']} | {$c['brands']} | " . $this->fmt($c['shows']) . " | {$c['leader']} |\n";
        }

        $mdPath = "{$outDir}/brand-city-ranking.md";
        file_put_contents($mdPath, $md);

        return [$csv, $mdPath];
    }

    /**
     * @param array<int,array{style:string,city:string,brand:string,shows:int,kw:int}> $styles
     * @return string[] пути созданных файлов
     */
    private function buildStyleMatrix(array $styles, string $outDir): array
    {
        if ($styles === []) {
            return [];
        }

        // ранг внутри ячейки стиль+город
        $cells = [];
        foreach ($styles as $r) {
            $cells[$r['style']][$r['city']][] = $r;
        }
        $csvRows = [];
        foreach ($cells as $list) {
            foreach ($list as $brandsInCell) {
                usort($brandsInCell, fn($a, $b) => $b['shows'] <=> $a['shows']);
                foreach ($brandsInCell as $rank => $r) {
                    $csvRows[] = [$r['style'], $r['city'], $rank + 1, $r['brand'], $r['shows'], $r['kw']];
                }
            }
        }
        usort($csvRows, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $csv = "{$outDir}/brand-style-city-matrix.csv";
        $this->writeCsv($csv, ['style', 'city', 'rank_in_cell', 'brand', 'monthly_shows', 'keywords'], $csvRows);

        // сводка по стилям
        $byStyle = [];
        foreach ($styles as $r) {
            $s = &$byStyle[$r['style']];
            $s['brands'] = ($s['brands'] ?? 0) + 1;
            $s['shows']  = ($s['shows'] ?? 0) + $r['shows'];
            if (!isset($s['lsh']) || $r['shows'] > $s['lsh']) {
                $s['leader'] = $r['brand'];
                $s['lcity']  = $r['city'];
                $s['lsh']    = $r['shows'];
            }
            unset($s);
        }
        uasort($byStyle, fn($a, $b) => $b['shows'] <=> $a['shows']);

        $cellCount = array_sum(array_map('count', $cells));

        $md  = "# Матрица: стиль × город → топ брендов\n\n";
        $md .= $this->sourceNote() . " Только бренды с проставленным стилем; объём показов по бренду (бренд с N стилями входит в N ячеек).\n\n";
        $md .= '**Записей (стиль×бренд):** ' . count($styles) . ' · **стилей:** ' . count($byStyle) . ' · **ячеек:** ' . $cellCount . "\n\n";
        $md .= $this->homonymNote() . "\n\n";

        $md .= "## Стили по совокупному спросу\n\n";
        $md .= "| # | Стиль | Брендов | Σ запросов/мес | Лидер (город) |\n|---|---|--:|--:|---|\n";
        $i = 0;
        foreach ($byStyle as $name => $s) {
            $md .= '| ' . (++$i) . " | {$name} | {$s['brands']} | " . $this->fmt($s['shows']) . " | {$s['leader']} ({$s['lcity']}) |\n";
        }

        $md .= "\n## Топ брендов в каждой ячейке (стиль → город)\n\n";
        foreach ($byStyle as $name => $s) {
            $md .= "### {$name}\n\n| Город | Топ бренды (запросов/мес) |\n|---|---|\n";
            // города ячейки по Σ спросу
            $citySum = [];
            foreach ($cells[$name] as $city => $list) {
                $citySum[$city] = array_sum(array_column($list, 'shows'));
            }
            arsort($citySum);
            foreach ($citySum as $city => $_) {
                $list = $cells[$name][$city];
                usort($list, fn($a, $b) => $b['shows'] <=> $a['shows']);
                $top   = array_slice($list, 0, self::TOP_PER_CELL);
                $cell  = implode(' · ', array_map(fn($x) => $x['brand'] . ' (' . $this->fmt($x['shows']) . ')', $top));
                $more  = count($list) > self::TOP_PER_CELL ? ' …+' . (count($list) - self::TOP_PER_CELL) : '';
                $md   .= "| {$city} | {$cell}{$more} |\n";
            }
            $md .= "\n";
        }

        $mdPath = "{$outDir}/brand-style-city-matrix.md";
        file_put_contents($mdPath, $md);

        return [$csv, $mdPath];
    }

    // ----------------------------------------------------------------- utils

    /**
     * @param array<int,array{city:string,brands?:int,shows:int,brand:string}> $brands
     * @return array<int,array{city:string,brands:int,shows:int,leader:string}>
     */
    private function aggregateCities(array $brands): array
    {
        $cities = [];
        foreach ($brands as $r) {
            $c = &$cities[$r['city']];
            $c['city']   = $r['city'];
            $c['brands'] = ($c['brands'] ?? 0) + 1;
            $c['shows']  = ($c['shows'] ?? 0) + $r['shows'];
            if (!isset($c['lsh']) || $r['shows'] > $c['lsh']) {
                $c['leader'] = $r['brand'];
                $c['lsh']    = $r['shows'];
            }
            unset($c);
        }
        uasort($cities, fn($a, $b) => $b['shows'] <=> $a['shows']);

        return array_values($cities);
    }

    private function printSummary(SymfonyStyle $io, array $brands, array $styles): void
    {
        $cities = $this->aggregateCities(array_map(fn($r) => $r + ['city' => $r['city']], $brands));
        $io->section('Города по спросу (топ-10)');
        $io->table(['#', 'Город', 'Брендов', 'Σ показов/мес', 'Лидер'],
            array_map(fn($i, $c) => [$i + 1, $c['city'], $c['brands'], $this->fmt($c['shows']), $c['leader']],
                array_keys(array_slice($cities, 0, 10)), array_slice($cities, 0, 10)));
        $io->text(sprintf('Всего: %d брендов, %d городов, %d записей стиль×бренд.', count($brands), count($cities), count($styles)));
    }

    /** Нормализация города: регистр + «г.»/«город» + дефис/пробелы → единый ключ. */
    private function normCity(string $city): string
    {
        $c = mb_strtolower(trim($city), 'UTF-8');
        $c = preg_replace('/^(г\.?\s*|город\s+)/u', '', $c);
        $c = preg_replace('/[-\s]+/u', ' ', $c);

        return mb_convert_case(trim($c), MB_CASE_TITLE, 'UTF-8');
    }

    private function fmt(int $n): string
    {
        return number_format($n, 0, '', ' ');
    }

    private function sourceNote(): string
    {
        return '_Источник: `brand_keyword.monthly_shows` (Яндекс Wordstat, регион 225/Россия). '
            . 'Активные бренды с указанным городом. Объём = сумма показов в месяц по всем фразам бренда. '
            . 'Сгенерировано ' . date('Y-m-d') . ' командой `app:seo:ranking`._';
    }

    private function homonymNote(): string
    {
        return '> ⚠️ Бренды со словарными именами (ТВОЕ, ТАЙНА, Родина, МЕЧ, ДВОР…) раздуты: '
            . 'Wordstat считает запросы самого слова, а не бренда. Это омоним-шум, не реальный спрос на бренд.';
    }

    /** @param list<list<string|int>> $rows */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '\\');
        }
        fclose($fh);
    }

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }
}
