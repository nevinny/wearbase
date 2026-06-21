<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Авто-масштабирование RAG-конвейера: ВСЕГДА держит 1 базовый демон полного конвейера,
 * а при заторе на конкретном этапе поднимает ДОПОЛНИТЕЛЬНЫЕ воркеры именно туда.
 * Запускается кроном раз в N минут — он же супервизор (реконсайл = и масштаб, и респаун).
 *
 * Базовый демон переключает состав стадий по здоровью LLM-сервера:
 *   .119 жив  → полный конвейер (вкл. embed/generate);
 *   .119 мёртв → net-only (без GPU-стадий — не жжём embed/generate-attempts о мёртвый сервер).
 *
 * Burst — ТОЛЬКО net-стадии (discover/fetch): generate не множим (один LLM-сервер → oversubscription
 * роняет gemma). claimPending = FOR UPDATE SKIP LOCKED → baseline + burst-воркеры дренят без коллизий.
 *
 *   php bin/console app:rag:autoscale --dry-run
 *   * /3 * * * * ... php bin/console app:rag:autoscale >> var/log/autoscale.log 2>&1
 */
#[AsCommand(
    name: 'app:rag:autoscale',
    description: 'Базовый демон полного конвейера + burst-воркеры на заторах (+ супервизор, health-gate)',
)]
class RagAutoscaleCommand extends Command
{
    private const RESERVE_CORES = 1; // системе

    // Базовые демоны (всегда-живые, по 1): net — всегда; gpu — только при живом .119
    // (иначе не жжём embed/generate-attempts). net∪gpu = полный конвейер (keywords — отдельно).
    // Раздельны, чтобы GPU не голодал, деля цикл с медленными net-стадиями.
    // discover ОТКЛЮЧЁН (Yandex Search API — платный). Вернуть: добавить 'discover,' в начало + в BURST.
    // enrich — в net (OpenRouter/сеть, не GPU; медленный с ретраями → не должен красть цикл у embed).
    // embed:200 — мелкая модель qwen-0.6b, давит backlog документов, не ждёт медленные LLM-стадии.
    private const BASELINE_NET = 'crawl,fetch,logo,push,enrich';
    private const BASELINE_GPU = 'embed:200,generate,faq,extract';

    /** Burst-стадии (только net). При queue > threshold поднимаем доп. воркеров: ceil(queue/per), ≤ max. */
    private const BURST = [
        'fetch' => [
            'sql' => "SELECT COUNT(*) FROM brand_source_url WHERE status='pending'",
            'threshold' => 2000, 'per' => 1500, 'max' => 3,
        ],
        // discover-burst ОТКЛЮЧЁН — Yandex Search API платный. Вернуть при возобновлении.
        // 'discover' => [
        //     'sql' => "SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id
        //               WHERE b.status IN ('active','new') AND (p.id IS NULL OR p.discovered_at IS NULL)",
        //     'threshold' => 1500, 'per' => 1200, 'max' => 1,
        // ],
    ];

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план, ничего не запускать/убивать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $daemons = $this->daemons();
        $llmAlive = $this->llmAlive();
        $io->writeln(sprintf('LLM-сервер: %s', $llmAlive ? 'жив' : 'МЁРТВ'));
        $this->healthGate($io, $llmAlive);

        // 1) Базовые демоны: net (всегда) + gpu (только при живом .119). Раздельны → GPU не голодает.
        $nonShard = array_values(array_filter($daemons, fn($d) => $d['shard'] === null));
        $recognized = [self::BASELINE_NET, self::BASELINE_GPU];
        foreach ($nonShard as $d) { // прибить нераспознанные базовые наборы (напр. старый «полный» демон)
            if (!in_array($d['stages'], $recognized, true)) {
                $io->writeln(sprintf('  убираю нераспознанный базовый набор «%s» (#%d)', $d['stages'], $d['pid']));
                if (!$dryRun) {
                    $this->killExact($d['stages']);
                }
            }
        }
        $this->ensureBaseline(self::BASELINE_NET, true, 'baseline-net', $nonShard, $io, $dryRun);
        $this->ensureBaseline(self::BASELINE_GPU, $llmAlive, 'baseline-gpu', $nonShard, $io, $dryRun);

        // 2) Burst-воркеры на заторах (net). Бюджет ядер: минус резерв и базовый.
        $budget = max(0, $this->detectCores() - self::RESERVE_CORES - 1);
        foreach (self::BURST as $stage => $b) {
            $q = (int) $this->db->fetchOne($b['sql']);
            $want = $q < $b['threshold'] ? 0 : min($b['max'], (int) ceil($q / $b['per']));
            $want = min($want, $budget);
            $budget -= $want;

            $cur = array_values(array_filter($daemons, fn($d) => $d['shard'] !== null && $d['stages'] === $stage));
            if (count($cur) === $want) {
                $io->writeln(sprintf('  burst %s: очередь %d → %d воркер(ов) — ок', $stage, $q, $want));
                continue;
            }
            $io->writeln(sprintf('  burst %s: очередь %d → %d воркер(ов) (было %d) — РЕКОНСАЙЛ', $stage, $q, $want, count($cur)));
            if (!$dryRun) {
                $this->killExact($stage);
                for ($shard = 0; $shard < $want; $shard++) {
                    $this->launch($stage, $shard, $want, 'burst-' . $stage);
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Держит ровно 1 базовый демон набора $stages, если $shouldRun; иначе гасит его.
     * @param list<array{pid:int,stages:string,shard:?int}> $nonShard снимок не-шард-демонов
     */
    private function ensureBaseline(string $stages, bool $shouldRun, string $logKey, array $nonShard, SymfonyStyle $io, bool $dryRun): void
    {
        $alive = array_values(array_filter($nonShard, fn($d) => $d['stages'] === $stages));
        if ($shouldRun) {
            if (count($alive) === 1) {
                $io->writeln(sprintf('  базовый %s: ок (#%d)', $logKey, $alive[0]['pid']));

                return;
            }
            $io->writeln(sprintf('  базовый %s: РЕКОНСАЙЛ (живых %d → 1)', $logKey, count($alive)));
            if (!$dryRun) {
                $this->killExact($stages);
                $this->launch($stages, null, 1, $logKey);
            }
        } elseif ($alive !== []) {
            $io->writeln(sprintf('  базовый %s: СТОП (.119 мёртв, живых %d)', $logKey, count($alive)));
            if (!$dryRun) {
                $this->killExact($stages);
            }
        } else {
            $io->writeln(sprintf('  базовый %s: не нужен (.119 мёртв)', $logKey));
        }
    }

    /**
     * Живые rag-демоны: [{pid, stages, shard|null}]. shard=null → базовый (без --shard).
     * @return list<array{pid:int,stages:string,shard:?int}>
     */
    private function daemons(): array
    {
        $out = (string) @shell_exec("pgrep -fl 'app:rag:daemon' 2>/dev/null");
        $res = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '' || !preg_match('/^(\d+)\s+.*--stages=(\S+)/', $line, $m)) {
                continue;
            }
            $res[] = [
                'pid'    => (int) $m[1],
                'stages' => $m[2],
                'shard'  => preg_match('/--shard=(\d+)/', $line, $s) === 1 ? (int) $s[1] : null,
            ];
        }

        return $res;
    }

    /** Убить демонов с ТОЧНО этим набором стадий (--stages=<x> с границей — не заденет надмножество). */
    private function killExact(string $stages): void
    {
        shell_exec('pkill -f ' . escapeshellarg('app:rag:daemon --stages=' . $stages . ' ') . ' 2>/dev/null');
        usleep(300_000);
    }

    private function launch(string $stages, ?int $shard, int $total, string $logKey): void
    {
        $shardArgs = $shard !== null ? sprintf('--shard=%d --total=%d ', $shard, $total) : '';
        $log = $this->projectDir . '/var/log/autoscale-' . $logKey . ($shard !== null ? '-' . $shard : '') . '.log';
        // Без `cd` (иначе `cd && …&` создаёт подоболочку, держащую pipe shell_exec → виснет).
        // Абсолютный путь к console + прямой nohup-редирект fds в файл → shell_exec возвращается сразу.
        $cmd = sprintf(
            'nohup php -d memory_limit=512M %s app:rag:daemon --stages=%s %s--no-debug >> %s 2>&1 < /dev/null &',
            escapeshellarg($this->projectDir . '/bin/console'),
            escapeshellarg($stages),
            $shardArgs,
            escapeshellarg($log)
        );
        shell_exec($cmd);
    }

    /** Ядер CPU: nproc (Linux) → sysctl hw.ncpu (macOS) → фолбэк. */
    private function detectCores(): int
    {
        foreach (['nproc 2>/dev/null', 'sysctl -n hw.ncpu 2>/dev/null'] as $probe) {
            $n = (int) trim((string) @shell_exec($probe));
            if ($n > 0) {
                return $n;
            }
        }

        return 4;
    }

    /** Если в очереди embed/generate есть работа, а LLM мёртв — варнинг (иначе generate «молча стоит»). */
    private function healthGate(SymfonyStyle $io, bool $llmAlive): void
    {
        if ($llmAlive) {
            return;
        }
        $embedQ = (int) $this->db->fetchOne("SELECT COUNT(*) FROM brand_source_document WHERE embedded = 0 AND deleted_at IS NULL");
        $genQ   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status = 'embedded'");
        if ($embedQ > 0 || $genQ > 0) {
            $io->warning(sprintf(
                'LLM-сервер недоступен — GPU-стадии СТОЯТ: embed=%d, generate=%d ждут. Базовый демон работает в net-режиме; подними сервер, чтобы GPU-стадии пошли.',
                $embedQ, $genQ
            ));
        }
    }

    /** TCP-проверка доступности LLM-сервера (host:port из LOCAL_LLM_URL/LOCAL_EMBED_URL). */
    private function llmAlive(): bool
    {
        // Symfony Dotenv кладёт env в $_SERVER/$_ENV (getenv по умолчанию НЕ заполняется).
        $env = static fn(string $k): string => (string) ($_SERVER[$k] ?? $_ENV[$k] ?? getenv($k) ?: '');
        $url = $env('LOCAL_LLM_URL') ?: $env('LOCAL_EMBED_URL');
        $p = $url !== '' ? parse_url($url) : false;
        $host = is_array($p) ? ($p['host'] ?? null) : null;
        if ($host === null) {
            return true; // адрес неизвестен → не шумим
        }
        $conn = @fsockopen($host, (int) ($p['port'] ?? 80), $errno, $errstr, 2.0);
        if ($conn !== false) {
            fclose($conn);
            return true;
        }

        return false;
    }
}
