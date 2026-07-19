<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use App\Service\LlmService;

/**
 * ReAct-агент: отвечает на вопросы владельца через итеративный цикл "мысль → действие → наблюдение".
 * Имеет доступ к инструментам: describe_schema (схема БД) и query_db (SQL SELECT).
 *
 * Формат общения с LLM:
 *   Мысль: ... (рассуждение, что нужно сделать)
 *   Инструмент: describe_schema
 *   — или —
 *   Инструмент: query_db
 *   SQL: SELECT ...
 *
 * После получения данных LLM либо вызывает следующий инструмент, либо выдаёт:
 *   Ответ: ... (финальный ответ владельцу)
 *
 * Максимум 6 шагов (итераций), защита от runaway.
 */
final class AgentSupervisor
{
    private const MAX_STEPS = 6;

    public function __construct(
        private readonly LlmService $llm,
        private readonly DescribeSchemaTool $schemaTool,
        private readonly DbQueryTool $dbTool,
    ) {
    }

    /**
     * Ответить на вопрос, используя ReAct-цикл с инструментами.
     */
    public function run(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            return 'Задай вопрос.';
        }

        // Шаг 0: всегда показываем схему первым шагом (экономит один LLM-вызов)
        $schema = $this->schemaTool->describe();

        $systemPrompt = $this->buildSystemPrompt();
        $history = $this->buildHistory($question, $schema, []);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $prompt = $this->formatPrompt($history);
            $raw = $this->llm->generate($prompt, $systemPrompt, local: true, think: false, timeout: 120);

            $parsed = $this->parseResponse($raw);

            if ($parsed['type'] === 'final') {
                return $parsed['content'];
            }

            if ($parsed['type'] === 'tool_call') {
                $toolResult = $this->executeTool($parsed['tool'], $parsed['args'] ?? '');

                $history[] = "Шаг " . ($step + 1) . " — Инструмент: {$parsed['tool']}";
                if ($parsed['args'] !== '') {
                    $history[] = "Аргументы: {$parsed['args']}";
                }
                $history[] = "Результат:\n{$toolResult}";
                $history[] = '';
                $history[] = 'Проанализируй результат. Если можешь ответить — напиши Ответ:. Иначе вызови другой инструмент.';
                continue;
            }

            // Если LLM вернула неразборчивый ответ — показываем как финальный
            return $raw !== '' ? $raw : 'Агент не смог сформулировать ответ. Попробуй переформулировать вопрос.';
        }

        return 'Агент не уложился в лимит шагов. Попробуй более конкретный вопрос.';
    }

    /**
     * Построить системный промпт с описанием инструментов.
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — аналитик данных wearbase.ru (каталог российских брендов одежды).

У тебя есть доступ к базе данных через SQL (SELECT ONLY).
Работай в ReAct-формате. Сначала подумай, затем вызови инструмент, проанализируй результат.

ИНСТРУМЕНТЫ:

1. describe_schema — показать схему таблиц БД. Без аргументов.
   Инструмент: describe_schema

2. query_db — выполнить SQL-запрос (SELECT ONLY).
   Инструмент: query_db
   SQL: SELECT ...

ПРАВИЛА:
- Сначала получи схему (describe_schema), чтобы понять структуру данных
- Проектируй SQL, отвечающий на вопрос владельца
- Используй GROUP BY, агрегатные функции, WHERE для фильтрации по датам
- Текущая дата: 2026-07-08. GSC данные имеют лаг 2-3 дня (нет за последние 2 дня)
- Для недельных сравнений используй YEARWEEK(day, 1)
- Если строк много — LIMIT
- Не выдумывай цифр — только данные из БД
- После получения данных: проанализируй, и либо вызови инструмент снова, либо дай ответ

ВАЖНО: Выводи ТОЛЬКО одну строку формата. Никаких лишних слов, пояснений, мыслей вслух.
Плохо: "Я подумал и решил выполнить запрос: Инструмент: query_db"
Хорошо: "Инструмент: query_db"

ФИНАЛЬНЫЙ ОТВЕТ:
Ответ: (текст на русском с цифрами и выводами)
PROMPT;
    }

    /**
     * Построить историю для первого вызова.
     *
     * @param array<string, string|int|float> $metrics
     * @param list<array> $tools
     */
    private function buildHistory(string $question, string $schema, array /* unused */ $metrics): array
    {
        return [
            "Схема БД:\n{$schema}",
            '',
            "Вопрос владельца: {$question}",
            '',
            'Какой SQL-запрос нужен? Вызови инструмент.',
        ];
    }

    /**
     * Собрать историю в один промпт.
     *
     * @param list<string> $history
     */
    private function formatPrompt(array $history): string
    {
        return implode("\n", $history);
    }

    /**
     * Распарсить ответ LLM: финальный ответ или вызов инструмента.
     *
     * @return array{type:string, content?:string, tool?:string, args?:string}
     */
    private function parseResponse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['type' => 'final', 'content' => 'Агент не дал ответа.'];
        }

        // Финальный ответ — ищем маркер в любом месте текста
        if (preg_match('/Ответ:\s*(.+)/su', $raw, $m)) {
            return ['type' => 'final', 'content' => trim($m[1])];
        }

        // Вызов инструмента: query_db (проверяем раньше describe_schema, т.к. он сложнее)
        if (preg_match('/Инструмент:\s*query_db/sim', $raw)) {
            $sql = '';

            // 1. Markdown-блок: ```sql ... ```
            if (preg_match('/```(?:sql)?\s*\n(.+?)\n```/s', $raw, $m)) {
                $sql = trim($m[1]);
            }

            // 2. SQL: SELECT ...
            if ($sql === '' && preg_match('/SQL:\s*(.+)/si', $raw, $m)) {
                $sql = trim($m[1]);
            }

            // 3. Строки после "Инструмент: query_db"
            if ($sql === '') {
                $lines = explode("\n", $raw);
                $capture = false;
                $sqlParts = [];
                foreach ($lines as $line) {
                    if ($capture) {
                        $trimmed = trim($line);
                        if ($trimmed === '' || preg_match('/^(?:Инструмент|Ответ):/i', $trimmed)) {
                            break;
                        }
                        $sqlParts[] = $line;
                    }
                    if (preg_match('/Инструмент:\s*query_db/sim', trim($line))) {
                        $capture = true;
                    }
                }
                if ($sqlParts !== []) {
                    $sql = trim(implode("\n", $sqlParts));
                }
            }

            if ($sql === '') {
                return ['type' => 'final', 'content' => 'Агент не указал SQL-запрос. Уточни вопрос.'];
            }

            return ['type' => 'tool_call', 'tool' => 'query_db', 'args' => $sql];
        }

        // Вызов инструмента: describe_schema
        if (preg_match('/Инструмент:\s*describe_schema/sim', $raw, $m)) {
            return ['type' => 'tool_call', 'tool' => 'describe_schema', 'args' => ''];
        }

        // Неструктурированный текст — возвращаем как есть
        return ['type' => 'final', 'content' => $raw];
    }

    /**
     * Выполнить инструмент и вернуть строковый результат.
     */
    private function executeTool(string $tool, string $args): string
    {
        return match ($tool) {
            'describe_schema' => $this->schemaTool->describe(),
            'query_db' => $this->formatQueryResult($this->dbTool->query($args)),
            default => "Неизвестный инструмент: {$tool}",
        };
    }

    /**
     * Отформатировать результат SQL-запроса в текст для LLM.
     *
     * @param array $result
     */
    private function formatQueryResult(array $result): string
    {
        if (!$result['success']) {
            return "Ошибка: {$result['error']}";
        }

        $data = $result['data'] ?? [];
        if ($data === []) {
            return 'Запрос выполнен, результат пуст (0 строк).';
        }

        $headers = array_keys($data[0]);
        $colWidths = [];
        foreach ($headers as $h) {
            $colWidths[$h] = mb_strlen((string) $h);
        }
        foreach ($data as $row) {
            foreach ($headers as $h) {
                $val = $this->formatValue($row[$h] ?? '');
                $colWidths[$h] = max($colWidths[$h], mb_strlen($val));
            }
        }

        $lines = [];
        $sep = '+-' . implode('-+-', array_map(static fn(int $w) => str_repeat('-', $w), $colWidths)) . '-+';
        $headerLine = '| ' . implode(' | ', array_map(
            static fn(string $h) => str_pad((string) $h, $colWidths[$h], ' '),
            $headers,
        )) . ' |';

        $lines[] = $sep;
        $lines[] = $headerLine;
        $lines[] = $sep;

        $shown = 0;
        foreach ($data as $row) {
            $vals = [];
            foreach ($headers as $h) {
                $vals[] = str_pad($this->formatValue($row[$h] ?? ''), $colWidths[$h], ' ');
            }
            $lines[] = '| ' . implode(' | ', $vals) . ' |';
            $shown++;
        }
        $lines[] = $sep;

        $info = "Строк: {$shown}";
        if (!empty($result['total_rows']) && $result['total_rows'] > $shown) {
            $info .= " (всего {$result['total_rows']}, показано {$shown})";
        }
        if (!empty($result['truncated'])) {
            $info .= ' [обрезано]';
        }

        $lines[] = $info;

        return implode("\n", $lines);
    }

    private function formatValue(mixed $val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }
        return (string) $val;
    }
}
