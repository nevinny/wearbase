<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Service\ArticleQaService;
use App\Service\BrandRagService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Бенч ОДНОЙ ollama-модели на генерации описаний: N grounded-брендов × R прогонов.
 * Слепой скоринг через article-QA (text-only); скорость (TTFT/tok-s/total) — из ollama-метрик.
 * Дописывает строку в сводную таблицу markdown-документа. Промпт = как в
 * LlmService::generateBrandDescription (grounded). Одна модель на вызов — оркестратор по списку.
 *
 *   php bin/console app:bench:models qwen3.6:27b docs/model-ab-bench.md
 */
#[AsCommand(name: 'app:bench:models', description: 'Бенч ollama-модели (качество article-QA + TTFT/tok-s) → строка в документ')]
class BenchModelsCommand extends Command
{
    private const RUNS   = 5;
    private const BRANDS = 5;
    private const JUDGE  = 'qwen3.5:27b'; // фиксированный судья grounding (один на все модели → честно)

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandRagService $rag,
        private readonly ArticleQaService $qa,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(LOCAL_LLM_URL)%')]
        private readonly string $ollamaUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('model', InputArgument::REQUIRED, 'ollama-модель, напр. qwen3.6:27b');
        $this->addArgument('doc', InputArgument::OPTIONAL, 'markdown-документ для строки результата', 'docs/model-ab-bench.md');
        $this->addOption('sample', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Показать 2 полных описания (eyeball), без замера/записи');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model = (string) $input->getArgument('model');
        $doc   = (string) $input->getArgument('doc');

        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT b.id FROM brand b JOIN brand_rag_pipeline p ON p.brand_id = b.id
             WHERE p.grounded = 1 AND b.title IS NOT NULL
             ORDER BY p.top_retrieval_score DESC LIMIT " . self::BRANDS,
        );
        $output->writeln(sprintf('### %s — бренды %s', $model, implode(',', $ids)));

        // --sample: показать 2 полных описания (eyeball), без замера и записи
        if ($input->getOption('sample')) {
            foreach (array_slice($ids, 0, 2) as $id) {
                $brand = $this->em->find(Brand::class, (int) $id);
                $ctx = $this->rag->retrieve($brand)['context'];
                if ($ctx === null) {
                    continue;
                }
                [$sys, $user] = $this->buildPrompt((string) $brand->getTitle(), $brand->getCity(), $ctx);
                $resp = $this->httpClient->request('POST', $this->ollamaUrl, [
                    'json' => ['model' => $model, 'stream' => false, 'think' => false,
                        'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]],
                    'timeout' => 600,
                ])->toArray(false);
                $output->writeln("\n═══════ {$brand->getTitle()} ═══════");
                $output->writeln($resp['message']['content'] ?? '(пусто)');
            }

            return Command::SUCCESS;
        }

        // Warm-up: грузим модель в VRAM (холодная загрузка ~минуты по x1-экспандеру),
        // чтобы TTFT замеряемых прогонов был «тёплым» (prompt_eval), а не load+prompt.
        // cold-load фиксируем отдельной метрикой.
        $coldLoad = 0.0;
        try {
            $w = $this->httpClient->request('POST', $this->ollamaUrl, [
                'json' => ['model' => $model, 'stream' => false, 'think' => false, 'messages' => [['role' => 'user', 'content' => 'привет']]],
                'timeout' => 600,
            ])->toArray(false);
            $coldLoad = ($w['load_duration'] ?? 0) / 1e9;
        } catch (\Throwable $e) {
            $output->writeln('  warm-up err: ' . $e->getMessage());
        }
        $output->writeln(sprintf('  warm-up: cold-load %.1fs', $coldLoad));

        $rows = [];
        foreach ($ids as $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            $ctx = $this->rag->retrieve($brand)['context'];
            if ($ctx === null) {
                $output->writeln("  skip #$id (нет контекста)");
                continue;
            }
            [$sys, $user] = $this->buildPrompt((string) $brand->getTitle(), $brand->getCity(), $ctx);
            for ($r = 1; $r <= self::RUNS; $r++) {
                try {
                    $resp = $this->httpClient->request('POST', $this->ollamaUrl, [
                        'json' => [
                            'model' => $model, 'stream' => false, 'think' => false,
                            'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
                        ],
                        'timeout' => 600,
                    ])->toArray(false);
                } catch (\Throwable $e) {
                    $output->writeln("  ERR #$id.$r: " . $e->getMessage());
                    continue;
                }
                $desc = $resp['message']['content'] ?? '';
                if ($desc === '') {
                    continue;
                }
                $ttft = ($resp['prompt_eval_duration'] ?? 0) / 1e9; // тёплый TTFT (модель уже в VRAM после warm-up)
                $ed   = ($resp['eval_duration'] ?? 0) / 1e9;
                $toks = $ed > 0 ? ($resp['eval_count'] ?? 0) / $ed : 0;
                $res  = $this->qa->check($desc);
                $mt   = $res['metrics'] ?? [];
                $rows[] = [
                    'overall' => (float) ($mt['overall'] ?? 0),
                    'human'   => (float) ($mt['human_likeness'] ?? 0),
                    'spam'    => (float) ($mt['spambrain'] ?? 0),
                    'words'   => preg_match_all('/[\p{L}\p{N}]+/u', $desc),
                    'passed'  => $res['passed'] ? 1 : 0,
                    'ttft'    => $ttft,
                    'toks'    => $toks,
                    'total'   => ($resp['total_duration'] ?? 0) / 1e9,
                    'desc'    => $desc,
                    'ctx'     => $ctx,
                ];
                $output->writeln(sprintf('  #%d.%d overall %.1f · words %d · %s · ttft %.1fs · %.1f tok/s',
                    $id, $r, $mt['overall'] ?? 0, end($rows)['words'], $res['passed'] ? 'PASS' : 'fail', $ttft, $toks));
            }
        }

        if ($rows === []) {
            $output->writeln('<error>Нет результатов (нет grounded-брендов с контекстом?)</error>');
            return Command::FAILURE;
        }

        // Grounding: фиксированный судья (qwen3.5:27b) оценивает заземлённость каждого текста на контекст.
        // Один свап на судью после генерации кандидата, затем судим все 25 (без свапа на каждый).
        $output->writeln('  grounding-судья (' . self::JUDGE . ')...');
        $gscores = [];
        foreach ($rows as $row) {
            $g = $this->judgeGrounding($row['ctx'], $row['desc']);
            if ($g >= 0) {
                $gscores[] = $g;
            }
        }
        $grounding = $gscores ? array_sum($gscores) / count($gscores) : 0;
        $output->writeln(sprintf('  grounding avg %.1f (по %d из %d)', $grounding, count($gscores), count($rows)));

        $avg = static fn(string $k) => array_sum(array_column($rows, $k)) / count($rows);
        $vram = $this->vram($model);
        $line = sprintf("| %s | %.1f | %.2f | %.2f | %.0f | %.0f | %.0f%% | %.1f | %.1f | %.1f | %.0f | %s |\n",
            $model, $avg('overall'), $avg('human'), $avg('spam'), $grounding, $avg('words'),
            100 * $avg('passed'), $avg('ttft'), $avg('toks'), $avg('total'), $coldLoad, $vram);
        file_put_contents($doc, $line, FILE_APPEND);
        $output->writeln("→ записано: $line");

        return Command::SUCCESS;
    }

    /** @return array{0:string,1:string} [system, user] — промпт как в generateBrandDescription (grounded). */
    private function buildPrompt(string $name, ?string $city, string $facts): array
    {
        $ctx = "Бренд: {$name}" . ($city ? "\nГород: {$city}" : '');
        $sys = 'Ты — копирайтер fashion-индустрии. Пишешь только на русском языке. '
            . 'Используй ИСКЛЮЧИТЕЛЬНО факты из блока «Проверенные факты»: не добавляй данные, которых там нет. '
            . 'Отвечаешь исключительно текстом описания, без заголовков и markdown.';
        $user = "Напиши развёрнутое описание для российского бренда одежды.\n\n{$ctx}\n\n"
            . "Проверенные факты о бренде (из официальных источников):\n{$facts}\n\nТребования:\n"
            . "- Объём: НЕ МЕНЕЕ 250 слов (обязательно)\n- Тон: информативный, без восторженных фраз\n"
            . "- Структура: 3–4 абзаца\n- Опирайся ТОЛЬКО на «Проверенные факты» выше; не добавляй то, чего там нет\n"
            . "Формат: только текст, без заголовков и markdown.";

        return [$sys, $user];
    }

    /** Слепая оценка заземлённости (0–100): доля утверждений описания, подтверждённых контекстом. -1 = ошибка. */
    private function judgeGrounding(string $context, string $desc): float
    {
        $sys = 'Ты — строгий аудитор фактов. Отвечаешь только числом.';
        $user = "ФАКТЫ (контекст):\n{$context}\n\nОПИСАНИЕ:\n{$desc}\n\n"
            . "Какая доля утверждений в ОПИСАНИИ подтверждается ФАКТАМИ из контекста? Оцени заземлённость "
            . "от 0 до 100 (100 = всё опирается на факты, ничего не выдумано; 0 = сплошь домыслы вне контекста). "
            . 'Ответь ТОЛЬКО числом 0–100.';
        try {
            $r = $this->httpClient->request('POST', $this->ollamaUrl, [
                'json' => [
                    'model' => self::JUDGE, 'stream' => false, 'think' => false,
                    'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
                ],
                'timeout' => 600,
            ])->toArray(false);
            $txt = $r['message']['content'] ?? '';
            if (preg_match('/\d{1,3}(?:[.,]\d+)?/', $txt, $m)) {
                return min(100.0, (float) str_replace(',', '.', $m[0]));
            }
        } catch (\Throwable) {
        }

        return -1;
    }

    private function vram(string $model): string
    {
        try {
            $ps = $this->httpClient->request('GET', preg_replace('#/api/chat$#', '/api/ps', $this->ollamaUrl), ['timeout' => 10])->toArray(false);
            foreach ($ps['models'] ?? [] as $m) {
                if (($m['name'] ?? '') === $model) {
                    return number_format(($m['size_vram'] ?? 0) / 1e9, 1);
                }
            }
        } catch (\Throwable) {
        }

        return '?';
    }
}
