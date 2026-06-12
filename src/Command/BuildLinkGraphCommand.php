<?php

namespace App\Command;

use App\Service\BrandLinkGraphService;
use App\Service\VectorStoreService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Построение жёсткого графа перелинковки брендов (docs/seo_adoption_plan.md, п.2).
 *
 * Запускать локально/на LLM-сервере (нужен Qdrant). Граф жёсткий: повторный запуск
 * НЕ трогает существующие рёбра — только добивает брендам без полного out-degree,
 * чинит рёбра на скрытые бренды и гарантирует in-degree >= 2 (нет сирот).
 *
 *   php bin/console app:brand:build-link-graph            # инкрементально
 *   php bin/console app:brand:build-link-graph --rebuild  # снести и построить заново
 */
#[AsCommand(
    name: 'app:brand:build-link-graph',
    description: 'Жёсткий граф внутренней перелинковки брендов (Qdrant + SQL fallback)',
)]
class BuildLinkGraphCommand extends Command
{
    // Сколько чанков бренда усредняем и сколько глобальных хитов берём на скоринг
    private const VECTORS_PER_BRAND = 64;
    private const SEARCH_TOP_K      = 120; // хитов-чанков; уникальных брендов после группировки ~втрое меньше — нужен запас на OUT_DEGREE=12

    public function __construct(
        private readonly Connection $db,
        private readonly VectorStoreService $vectorStore,
        private readonly BrandLinkGraphService $graph,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, 'Снести граф и построить заново');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('rebuild')) {
            $this->db->executeStatement('DELETE FROM brand_related');
            $io->warning('Граф снесён (--rebuild).');
        }

        $activeIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM brand WHERE status = 'active' ORDER BY id",
        ));
        $activeSet = array_flip($activeIds);
        $io->text(sprintf('Активных брендов: %d', count($activeIds)));

        // --- Этап 1: исходящие рёбра (embedding → SQL fallback) ---
        $embedded = $fallback = $skipped = 0;
        foreach ($activeIds as $i => $brandId) {
            if ($this->graph->outDegree($brandId) >= BrandLinkGraphService::OUT_DEGREE) {
                $skipped++;
                continue;
            }

            $candidates = $this->embeddingCandidates($brandId, $activeSet);
            if ($candidates !== []) {
                $this->graph->addEdges($brandId, $candidates, 'embedding');
                $embedded++;
            }
            // Добивка до OUT_DEGREE (и весь бренд без векторов) — SQL-кандидатами
            if ($this->graph->outDegree($brandId) < BrandLinkGraphService::OUT_DEGREE) {
                foreach ($this->graph->fallbackCandidates($brandId) as $cid) {
                    if ($this->graph->outDegree($brandId) >= BrandLinkGraphService::OUT_DEGREE) {
                        break;
                    }
                    $this->graph->addEdges($brandId, [$cid], $this->graph->classifyFallback($brandId, $cid));
                }
                if ($candidates === []) {
                    $fallback++;
                }
            }

            if (($i + 1) % 50 === 0) {
                $io->text(sprintf('  … %d/%d', $i + 1, count($activeIds)));
            }
        }
        $io->text(sprintf('Исходящие: embedding %d · fallback %d · уже полные %d', $embedded, $fallback, $skipped));

        // --- Этап 2: рёбра на неактивные + гарантия входящих ---
        $dead = $this->graph->replaceDeadEdges();
        if ($dead > 0) {
            $io->text(sprintf('Заменено мёртвых рёбер: %d', $dead));
        }

        $woven = 0;
        foreach ($this->graph->orphans() as $orphan) {
            $woven += $this->graph->ensureIncoming($orphan['id']);
        }
        $io->text(sprintf('Балансировка in-degree: добавлено входящих %d', $woven));

        // --- Отчёт ---
        $stats = $this->db->fetchAssociative(
            'SELECT COUNT(*) edges, COUNT(DISTINCT brand_id) brands FROM brand_related',
        );
        $sources = $this->db->fetchAllKeyValue(
            'SELECT source, COUNT(*) FROM brand_related GROUP BY source ORDER BY COUNT(*) DESC',
        );
        $orphans = $this->graph->orphans();

        $io->section('Итог');
        $io->text(sprintf('Рёбер: %d · брендов с исходящими: %d', $stats['edges'], $stats['brands']));
        foreach ($sources as $source => $count) {
            $io->text(sprintf('  %s: %d', $source, $count));
        }
        if ($orphans === []) {
            $io->success(sprintf('Сирот нет: каждый активный бренд имеет >= %d входящих.', BrandLinkGraphService::MIN_IN));
        } else {
            $io->warning(sprintf('Сирот (in-degree < %d): %d — %s',
                BrandLinkGraphService::MIN_IN,
                count($orphans),
                implode(', ', array_map(static fn ($o) => (string) $o['id'], array_slice($orphans, 0, 20))),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Кандидаты по близости эмбеддингов: средний вектор чанков бренда →
     * глобальный поиск → max-score по brand_id из payload, только активные.
     *
     * @param array<int,int> $activeSet brand_id => index
     * @return array<int,int> id кандидатов по убыванию близости
     */
    private function embeddingCandidates(int $brandId, array $activeSet): array
    {
        try {
            $vectors = $this->vectorStore->brandVectors($brandId, self::VECTORS_PER_BRAND);
        } catch (\Throwable) {
            return []; // Qdrant недоступен — команда продолжает на SQL fallback
        }
        if ($vectors === []) {
            return [];
        }

        $dim  = count($vectors[0]);
        $mean = array_fill(0, $dim, 0.0);
        foreach ($vectors as $vector) {
            for ($d = 0; $d < $dim; $d++) {
                $mean[$d] += $vector[$d];
            }
        }
        $n = count($vectors);
        for ($d = 0; $d < $dim; $d++) {
            $mean[$d] /= $n;
        }

        try {
            $hits = $this->vectorStore->search($mean, self::SEARCH_TOP_K);
        } catch (\Throwable) {
            return [];
        }

        $scores = [];
        foreach ($hits as $hit) {
            $hitBrand = (int) ($hit['payload']['brand_id'] ?? 0);
            if ($hitBrand === 0 || $hitBrand === $brandId || !isset($activeSet[$hitBrand])) {
                continue;
            }
            $score = (float) $hit['score'];
            if (!isset($scores[$hitBrand]) || $score > $scores[$hitBrand]) {
                $scores[$hitBrand] = $score;
            }
        }
        arsort($scores);

        return array_slice(array_keys($scores), 0, BrandLinkGraphService::OUT_DEGREE);
    }
}
