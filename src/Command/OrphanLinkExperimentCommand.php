<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BrandLinkGraphService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * HADI-эксперимент с сиротами графа перелинковки (docs/hadi_orphan_links.md).
 *
 * План строится на Mac (scripts/linkgraph-audit/orphan_plan.py: Qdrant + GSC), ключи — slug'и
 * (id dev ≠ прод). Здесь, на проде:
 *   apply  --plan=var/orphan_plan.json  назначить группы, снять baseline, вписать рёбра донор→сирота
 *   status                               группы, доза treatment, «утечка» входящих в control
 *   end    --verdict=validated|inconclusive  закрыть эксперимент (control размораживается)
 *   revert                               откатить рёбра по журналу + закрыть с verdict=reverted
 */
#[AsCommand(name: 'app:linkgraph:orphan-experiment', description: 'HADI-эксперимент: входящие рёбра сиротам графа (treatment) vs контроль')]
final class OrphanLinkExperimentCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'apply | status | end | revert')
            ->addOption('experiment', null, InputOption::VALUE_REQUIRED, 'Имя эксперимента', 'orphans-2026-09')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'JSON-план (apply)')
            ->addOption('verdict', null, InputOption::VALUE_REQUIRED, 'validated | inconclusive (end)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Всё в транзакции с откатом');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $exp = (string) $input->getOption('experiment');

        return match ((string) $input->getArgument('action')) {
            'apply'  => $this->apply($io, $exp, (string) $input->getOption('plan'), (bool) $input->getOption('dry-run')),
            'status' => $this->status($io, $exp),
            'end'    => $this->end($io, $exp, (string) $input->getOption('verdict')),
            'revert' => $this->revert($io, $exp, (bool) $input->getOption('dry-run')),
            default  => $this->fail($io, 'action: apply | status | end | revert'),
        };
    }

    private function apply(SymfonyStyle $io, string $exp, string $planPath, bool $dryRun): int
    {
        if ($planPath === '' || !is_file($planPath)) {
            return $this->fail($io, '--plan=<json> обязателен');
        }
        $plan = json_decode((string) file_get_contents($planPath), true, flags: JSON_THROW_ON_ERROR);

        $ids = [];
        foreach ($this->db->fetchAllAssociative("SELECT id, slug FROM brand WHERE status = 'active'") as $r) {
            $ids[$r['slug']] = (int) $r['id'];
        }

        $this->db->beginTransaction();
        try {
            // 1. Группы + baseline. Повторный apply не переназначает (UNIQUE experiment+brand).
            $arms = ['treatment' => 0, 'control' => 0, 'missing' => 0];
            foreach ($plan['population'] as $p) {
                $id = $ids[$p['slug']] ?? null;
                if (!in_array($p['arm'] ?? null, ['treatment', 'control'], true)) {
                    throw new \InvalidArgumentException("arm должен быть treatment|control: {$p['slug']}");
                }
                if ($id === null) {
                    $arms['missing']++;
                    continue;
                }
                $exists = $this->db->fetchOne(
                    'SELECT 1 FROM link_experiment WHERE experiment = :e AND brand_id = :b',
                    ['e' => $exp, 'b' => $id],
                );
                if (!$exists) {
                    $this->db->insert('link_experiment', [
                        'experiment'     => $exp,
                        'brand_id'       => $id,
                        'arm'            => $p['arm'],
                        'baseline_state' => ($p['baseline_state'] ?? '') ?: null,
                    ]);
                }
                $arms[$p['arm']]++;
            }

            $armOf = [];
            foreach ($this->db->fetchAllAssociative('SELECT brand_id, arm FROM link_experiment WHERE experiment = :e AND ended_at IS NULL', ['e' => $exp]) as $r) {
                $armOf[(int) $r['brand_id']] = $r['arm'];
            }

            // 2. Рёбра донор→сирота: свободный слот донора, иначе вытесняем его fill-ребро.
            $stat = ['added_free' => 0, 'added_fill' => 0, 'exists' => 0, 'no_slot' => 0, 'rejected' => 0];
            foreach ($plan['edges'] as $e) {
                $target = $ids[$e['target']] ?? null;
                $donor  = $ids[$e['donor']] ?? null;
                // treatment-таргет обязателен; донор — не control (его исходящие не трогаем)
                if ($target === null || $donor === null || $donor === $target
                    || ($armOf[$target] ?? null) !== 'treatment' || ($armOf[$donor] ?? null) === 'control') {
                    $stat['rejected']++;
                    continue;
                }
                if ($this->db->fetchOne('SELECT 1 FROM brand_related WHERE brand_id = :d AND related_brand_id = :t', ['d' => $donor, 't' => $target])) {
                    $stat['exists']++;
                    continue;
                }

                $used = array_map('intval', $this->db->fetchFirstColumn('SELECT position FROM brand_related WHERE brand_id = :d', ['d' => $donor]));
                $free = array_values(array_diff(range(1, BrandLinkGraphService::OUT_DEGREE), $used));

                if ($free !== []) {
                    $this->db->insert('brand_related', [
                        'brand_id' => $donor, 'related_brand_id' => $target, 'position' => $free[0], 'source' => 'embedding',
                    ]);
                    $this->logEdge($exp, $donor, $target, $free[0], $e, null, null);
                    $stat['added_free']++;
                    continue;
                }

                // Слотов нет → fill-ребро донора (farm-like добивка, решение 2026-07-19 её убить).
                // Вытесняем то, чей таргет богаче входящими, — чтобы не наплодить новых сирот.
                $fill = $this->db->fetchAssociative(
                    "SELECT r.id, r.position, r.related_brand_id, r.source FROM brand_related r
                     WHERE r.brand_id = :d AND r.source = 'fill'
                     ORDER BY (SELECT COUNT(*) FROM brand_related x WHERE x.related_brand_id = r.related_brand_id) DESC
                     LIMIT 1",
                    ['d' => $donor],
                );
                if ($fill === false) {
                    $stat['no_slot']++;
                    continue;
                }
                $this->db->update('brand_related', ['related_brand_id' => $target, 'source' => 'embedding'], ['id' => $fill['id']]);
                $this->logEdge($exp, $donor, $target, (int) $fill['position'], $e, (int) $fill['related_brand_id'], $fill['source']);
                $stat['added_fill']++;
            }

            $dryRun ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $ex) {
            $this->db->rollBack();
            throw $ex;
        }

        $io->definitionList(...array_map(static fn ($k, $v) => [$k => $v], array_keys($arms + $stat), $arms + $stat));
        $io->success($dryRun ? 'dry-run: откатено' : "Эксперимент {$exp} применён");

        return Command::SUCCESS;
    }

    /** @param array<string,mixed> $e */
    private function logEdge(string $exp, int $donor, int $target, int $pos, array $e, ?int $oldTarget, ?string $oldSource): void
    {
        $this->db->insert('link_experiment_edge', [
            'experiment'    => $exp,
            'donor_id'      => $donor,
            'target_id'     => $target,
            'position'      => $pos,
            'tier'          => (string) ($e['tier'] ?? 'free'),
            'score'         => isset($e['score']) ? round((float) $e['score'], 3) : null,
            'old_target_id' => $oldTarget,
            'old_source'    => $oldSource,
        ]);
    }

    private function status(SymfonyStyle $io, string $exp): int
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT x.arm, COUNT(*) n, MIN(x.assigned_at) assigned, MAX(x.ended_at) ended,
                    SUM(CASE WHEN (SELECT COUNT(*) FROM brand_related r WHERE r.related_brand_id = x.brand_id) = 0 THEN 1 ELSE 0 END) in0,
                    SUM(CASE WHEN (SELECT COUNT(*) FROM brand_related r WHERE r.related_brand_id = x.brand_id) >= 2 THEN 1 ELSE 0 END) in2
             FROM link_experiment x WHERE x.experiment = :e GROUP BY x.arm",
            ['e' => $exp],
        );
        $io->table(['arm', 'n', 'assigned', 'ended', 'in-degree 0', 'in-degree ≥2'], array_map('array_values', $rows));

        $tiers = $this->db->fetchAllAssociative(
            'SELECT tier, COUNT(*) n, ROUND(AVG(score), 3) score FROM link_experiment_edge WHERE experiment = :e AND reverted_at IS NULL GROUP BY tier',
            ['e' => $exp],
        );
        $io->table(['tier', 'рёбер', 'ср. score'], array_map('array_values', $tiers));

        foreach ($rows as $r) {
            if ($r['arm'] === 'control' && (int) $r['in0'] < (int) $r['n'] && $r['ended'] === null) {
                $io->warning(sprintf('Утечка: %d брендов control получили входящие рёбра', (int) $r['n'] - (int) $r['in0']));
            }
        }

        return Command::SUCCESS;
    }

    private function end(SymfonyStyle $io, string $exp, string $verdict): int
    {
        if (!in_array($verdict, ['validated', 'inconclusive'], true)) {
            return $this->fail($io, '--verdict=validated|inconclusive (для отката — action revert)');
        }
        $n = $this->db->executeStatement(
            'UPDATE link_experiment SET ended_at = CURRENT_TIMESTAMP, verdict = :v WHERE experiment = :e AND ended_at IS NULL',
            ['v' => $verdict, 'e' => $exp],
        );
        $io->success("Закрыто: {$n} участников, verdict={$verdict}. Control разморожен.");

        return Command::SUCCESS;
    }

    private function revert(SymfonyStyle $io, string $exp, bool $dryRun): int
    {
        $edges = $this->db->fetchAllAssociative(
            'SELECT * FROM link_experiment_edge WHERE experiment = :e AND reverted_at IS NULL',
            ['e' => $exp],
        );

        $this->db->beginTransaction();
        try {
            foreach ($edges as $e) {
                $where = ['brand_id' => $e['donor_id'], 'related_brand_id' => $e['target_id']];
                // Системная операция отката графа — физический delete допустим (CLAUDE.md).
                try {
                    $e['old_target_id'] === null
                        ? $this->db->delete('brand_related', $where)
                        : $this->db->update('brand_related', ['related_brand_id' => $e['old_target_id'], 'source' => $e['old_source']], $where);
                } catch (UniqueConstraintViolationException) {
                    // донор уже сам ссылается на старый таргет — просто убираем экспериментальное ребро
                    $this->db->delete('brand_related', $where);
                }
                $this->db->executeStatement('UPDATE link_experiment_edge SET reverted_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => $e['id']]);
            }
            $this->db->executeStatement(
                "UPDATE link_experiment SET ended_at = CURRENT_TIMESTAMP, verdict = 'reverted' WHERE experiment = :e AND ended_at IS NULL",
                ['e' => $exp],
            );
            $dryRun ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $ex) {
            $this->db->rollBack();
            throw $ex;
        }

        $io->success(sprintf('%s: откатено рёбер %d', $dryRun ? 'dry-run' : 'готово', count($edges)));

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, string $msg): int
    {
        $io->error($msg);

        return Command::INVALID;
    }
}
