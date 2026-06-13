<?php

namespace App\Command;

use App\Service\NearDuplicateDetector;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Аудит near-duplicate описаний активных брендов (scaled-content / doorway-риск,
 * docs/seo_adoption_plan.md п.6). Считает попарный Jaccard по word-shingles и
 * показывает пары ≥ порога: ≥0.85 — DROP-кандидаты (near-duplicate), 0.60–0.85 —
 * каннибализация. Read-only: ничего не меняет, только отчёт (что чинить — решает человек).
 *
 *   php bin/console app:seo:near-dup                 # пары ≥0.60
 *   php bin/console app:seo:near-dup --threshold=0.85 # только DROP-уровень
 *   php bin/console app:seo:near-dup --export=/tmp/nd.json
 *
 * Сложность O(N²) — для текущего каталога (сотни active) приемлемо; на тысячах
 * прогонять точечно (--threshold выше = меньше шума, считается так же, фильтр на выводе).
 */
#[AsCommand(
    name: 'app:seo:near-dup',
    description: 'Аудит near-duplicate описаний брендов (Jaccard по shingles)',
)]
class NearDupAuditCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly NearDuplicateDetector $detector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Минимальный Jaccard для вывода пары', (string) NearDuplicateDetector::WARN_THRESHOLD)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько пар показать (по убыванию сходства)', '50')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Экспорт всех найденных пар в JSON')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $threshold = (float) $input->getOption('threshold');
        $limit     = max(1, (int) $input->getOption('limit'));
        $export    = $input->getOption('export');

        $io->title('Near-duplicate аудит описаний брендов');

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, slug, title, description FROM brand
             WHERE status = 'active' AND description IS NOT NULL AND CHAR_LENGTH(description) > 0
             ORDER BY id",
        );
        $n = count($rows);
        $io->text(sprintf('Активных брендов с описанием: %d · порог Jaccard ≥ %.2f', $n, $threshold));
        if ($n < 2) {
            $io->success('Сравнивать нечего.');
            return Command::SUCCESS;
        }

        // Предпосчёт shingle-множеств (один раз на бренд)
        $shingles = $meta = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $shingles[$id] = $this->detector->shingles((string) $row['description']);
            $meta[$id]     = ['slug' => $row['slug'], 'title' => $row['title']];
        }
        $ids = array_keys($shingles);

        $pairs = [];
        $drop  = $warn = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $score = $this->detector->jaccard($shingles[$ids[$i]], $shingles[$ids[$j]]);
                if ($score < $threshold) {
                    continue;
                }
                $level = $score >= NearDuplicateDetector::DROP_THRESHOLD ? 'DROP' : 'WARN';
                $level === 'DROP' ? $drop++ : $warn++;
                $pairs[] = [
                    'score' => round($score, 3),
                    'level' => $level,
                    'a'     => $meta[$ids[$i]]['slug'],
                    'b'     => $meta[$ids[$j]]['slug'],
                ];
            }
        }

        usort($pairs, static fn($x, $y) => $y['score'] <=> $x['score']);

        if ($export) {
            file_put_contents($export, json_encode($pairs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $io->text(sprintf('Экспортировано пар: %d → %s', count($pairs), $export));
        }

        if ($pairs === []) {
            $io->success(sprintf('Пар с Jaccard ≥ %.2f не найдено — дублей нет.', $threshold));
            return Command::SUCCESS;
        }

        $io->table(
            ['Jaccard', 'Уровень', 'Бренд A', 'Бренд B'],
            array_map(static fn($p) => [$p['score'], $p['level'], $p['a'], $p['b']], array_slice($pairs, 0, $limit)),
        );

        $io->section('Итог');
        $io->text(sprintf('Пар DROP (≥%.2f): %d · WARN (≥%.2f): %d · всего: %d',
            NearDuplicateDetector::DROP_THRESHOLD, $drop,
            NearDuplicateDetector::WARN_THRESHOLD, $warn, count($pairs)));
        if ($drop > 0) {
            $io->warning(sprintf('%d пар near-duplicate (≥%.2f) — кандидаты на консолидацию/регенерацию.', $drop, NearDuplicateDetector::DROP_THRESHOLD));
        }

        return Command::SUCCESS;
    }
}
