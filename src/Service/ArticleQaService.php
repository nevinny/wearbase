<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * QA-гейт сгенерированного текста через article-qa-toolkit (tools/article-qa-toolkit).
 *
 * Тулкит — python stdlib (13 эвристических модулей: AI-почерк, переспам, повторы,
 * вода, читаемость; методология SEO Guide 4.9). Дёргаем CLI через Process, парсим
 * JSON-отчёт и применяем СВОИ пороги: SB/HL берём из методологии, а ось Reader Value
 * НЕ применяем — она откалибрована под статьи 1200+ слов (length_factor), описания
 * брендов 300–500 слов её структурно не проходят.
 *
 * Деградация мягкая (fail-open): если python/тулкит недоступны или упали — пишем
 * warning в лог и пропускаем текст, чтобы инфраструктурный сбой не остановил
 * многодневный батч генерации. Контентный FAIL — это только вердикт самого гейта.
 */
class ArticleQaService
{
    /** SpamBrain ≥7 — переспам ключей, плотность AI-маркеров, thin-content. */
    private const MIN_SPAMBRAIN = 7.0;
    /** Human-likeness ≥8 — AI-клише/штампы + неестественный ритм предложений. */
    private const MIN_HUMAN_LIKENESS = 8.0;
    /** Средневзвешенный балл всех модулей; реальные хорошие описания дают 82–88. */
    private const MIN_OVERALL = 75.0;

    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Проверяет русскоязычный текст. Возвращает вердикт:
     *   passed  — публиковать можно (или гейт выключен/недоступен — fail-open)
     *   checked — гейт реально отработал (false при fail-open)
     *   reasons — человекочитаемые причины провала (пусто при passed)
     *   metrics — overall/spambrain/human_likeness для лога и отладки
     *
     * @return array{passed: bool, checked: bool, reasons: string[], metrics: array}
     */
    public function check(string $text, string $lang = 'ru'): array
    {
        if (!$this->enabled) {
            return ['passed' => true, 'checked' => false, 'reasons' => [], 'metrics' => []];
        }

        $tmpDir  = $this->projectDir . '/var/qa';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $base     = $tmpDir . '/check_' . bin2hex(random_bytes(8));
        $textFile = $base . '.txt';
        $jsonFile = $base . '.json';

        try {
            file_put_contents($textFile, $text);

            $process = new Process([
                'python3',
                $this->projectDir . '/tools/article-qa-toolkit/check_article.py',
                $textFile,
                '--lang', $lang,
                '--gate',
                '--no-color',
                '--json', $jsonFile,
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run(); // exit 1 = контентный FAIL тулкита, не ошибка — вердикт берём из JSON

            if (!is_file($jsonFile)) {
                $this->logger->warning('ArticleQa: тулкит не вернул JSON-отчёт — fail-open', [
                    'exit'   => $process->getExitCode(),
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
                ]);

                return ['passed' => true, 'checked' => false, 'reasons' => [], 'metrics' => []];
            }

            $report = json_decode((string) file_get_contents($jsonFile), true);
            if (!is_array($report) || !isset($report['modules'])) {
                $this->logger->warning('ArticleQa: некорректный JSON-отчёт — fail-open');

                return ['passed' => true, 'checked' => false, 'reasons' => [], 'metrics' => []];
            }

            return $this->verdict($report);
        } catch (\Throwable $e) {
            $this->logger->warning('ArticleQa: сбой запуска тулкита — fail-open: ' . $e->getMessage());

            return ['passed' => true, 'checked' => false, 'reasons' => [], 'metrics' => []];
        } finally {
            @unlink($textFile);
            @unlink($jsonFile);
        }
    }

    /** @return array{passed: bool, checked: bool, reasons: string[], metrics: array} */
    private function verdict(array $report): array
    {
        $overall = (float) ($report['overall'] ?? 0.0);
        $sb = $hl = null;

        foreach ($report['modules'] as $module) {
            $metrics = $module['metrics'] ?? [];
            if (array_key_exists('gate_passed', $metrics)) {
                $sb = isset($metrics['spambrain']) ? (float) $metrics['spambrain'] : null;
                $hl = isset($metrics['human_likeness']) ? (float) $metrics['human_likeness'] : null;
            }
        }

        $reasons = [];
        if ($overall < self::MIN_OVERALL) {
            $reasons[] = sprintf('overall %.1f < %.0f', $overall, self::MIN_OVERALL);
        }
        if ($sb !== null && $sb < self::MIN_SPAMBRAIN) {
            $reasons[] = sprintf('SpamBrain %.1f < %.0f (переспам/AI-маркеры/thin)', $sb, self::MIN_SPAMBRAIN);
        }
        if ($hl !== null && $hl < self::MIN_HUMAN_LIKENESS) {
            $reasons[] = sprintf('Human-likeness %.1f < %.0f (AI-почерк)', $hl, self::MIN_HUMAN_LIKENESS);
        }

        // Деталь для лога: error-замечания контентных модулей (кроме самого гейта —
        // его FAIL по Reader Value мы сознательно не применяем).
        if ($reasons !== []) {
            foreach ($report['modules'] as $module) {
                if (array_key_exists('gate_passed', $module['metrics'] ?? [])) {
                    continue;
                }
                foreach ($module['findings'] ?? [] as $finding) {
                    if (($finding['level'] ?? '') === 'error') {
                        $reasons[] = sprintf('[%s] %s', $module['name'] ?? '?', mb_substr($finding['msg'] ?? '', 0, 120));
                    }
                }
            }
        }

        return [
            'passed'  => $reasons === [],
            'checked' => true,
            'reasons' => $reasons,
            'metrics' => ['overall' => $overall, 'spambrain' => $sb, 'human_likeness' => $hl],
        ];
    }
}
