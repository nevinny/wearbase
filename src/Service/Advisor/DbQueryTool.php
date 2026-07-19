<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use Doctrine\DBAL\Connection;

/**
 * Read-only SQL-исполнитель для LLM-агента. SELECT ONLY с жёсткими гейтами:
 * - DDL/DML блокируется на уровне регулярки
 * - EXPLAIN перед выполнением (если оценка > MAX_EXPLAIN_ROWS → ошибка)
 * - Таймаут через SET max_execution_time
 * - Лимит строк в ответе
 * - Логирование всех запросов (для аудита)
 */
final class DbQueryTool
{
    private const MAX_ROWS = 1000;
    private const MAX_EXPLAIN_ROWS = 50000;
    private const MAX_EXECUTION_SEC = 15;

    /** @var list<array{sql:string,error?:string,rows:int,duration_ms:float}> */
    private array $log = [];

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Выполнить SELECT и вернуть результат как отформатированную таблицу.
     *
     * @return array{success:bool,data?:list<array<string,mixed>>,error?:string,truncated?:bool}
     */
    public function query(string $sql): array
    {
        $sql = trim($sql);

        // --- Гейт 1: только SELECT / WITH (CTE) ---
        if (!preg_match('/^\s*(?:SELECT|WITH)\s/i', $sql)) {
            $err = 'Только SELECT разрешён. DDL/DML не выполняются.';
            $this->log[] = ['sql' => $sql, 'error' => $err, 'rows' => 0, 'duration_ms' => 0];
            return ['success' => false, 'error' => $err];
        }

        // --- Гейт 2: блокировка опасных паттернов ---
        $blocked = [
            '/\bINTO\s+(OUT|DUMP)FILE\b/i',
            '/\bINTO\s+TABLE\b/i',
            '/\b(?:INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|LOAD)\b/i',
            '/@@(?:datadir|basedir|plugin_dir|tmpdir)/i',
            '/\bSLEEP\s*\(/i',
            '/\bBENCHMARK\s*\(/i',
            '/\/\*.*!\d+/', // MySQL hints injection
        ];
        foreach ($blocked as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                $err = 'Запрос содержит заблокированный паттерн.';
                $this->log[] = ['sql' => $sql, 'error' => $err, 'rows' => 0, 'duration_ms' => 0];
                return ['success' => false, 'error' => $err];
            }
        }

        // --- Гейт 3: EXPLAIN для оценки rows ---
        $explainError = $this->checkExplain($sql);
        if ($explainError !== null) {
            $this->log[] = ['sql' => $sql, 'error' => $explainError, 'rows' => 0, 'duration_ms' => 0];
            return ['success' => false, 'error' => $explainError];
        }

        // --- Выполнение с таймаутом ---
        $start = microtime(true);
        try {
            $this->db->executeStatement('SET max_execution_time = ' . (self::MAX_EXECUTION_SEC * 1000));
            $rows = $this->db->fetchAllAssociative($sql);

            $duration = (microtime(true) - $start) * 1000;
            $total = count($rows);
            $truncated = $total > self::MAX_ROWS;

            if ($truncated) {
                $rows = array_slice($rows, 0, self::MAX_ROWS);
            }

            $this->log[] = ['sql' => $sql, 'rows' => $total, 'duration_ms' => round($duration, 1)];

            return [
                'success' => true,
                'data' => $rows,
                'truncated' => $truncated,
                'total_rows' => $total,
            ];
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            $msg = 'Ошибка запроса: ' . $e->getMessage();
            $this->log[] = ['sql' => $sql, 'error' => $msg, 'rows' => 0, 'duration_ms' => round($duration, 1)];
            return ['success' => false, 'error' => $msg];
        }
    }

    /**
     * @return list<array{sql:string,error?:string,rows:int,duration_ms:float}>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    private function checkExplain(string $sql): ?string
    {
        try {
            $plan = $this->db->fetchAllAssociative('EXPLAIN ' . $sql);
        } catch (\Throwable $e) {
            return 'Ошибка при EXPLAIN: ' . $e->getMessage();
        }

        $totalRows = 0;
        foreach ($plan as $row) {
            $rows = isset($row['rows']) ? (int) $row['rows'] : 0;
            $totalRows += $rows;
        }

        if ($totalRows > self::MAX_EXPLAIN_ROWS) {
            return sprintf(
                'Запрос слишком тяжёлый: оценка %d строк (макс %d). Добавь WHERE или уточни фильтр.',
                $totalRows,
                self::MAX_EXPLAIN_ROWS,
            );
        }

        return null;
    }
}
