<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза A исполнительного контура советника (docs/advisor.md §Цикл идей, §Таксономия):
 *  - advisor_idea:       action_class (a|b|c, детерминированная классификация DecisionMaker) + needs_human.
 *  - advisor_experiment: поля гитфлоу-трекера (stage-курсор статус-машины исполнения, worktree,
 *                        отчёты тестов/гейтов, PR, попытки, факт апрува человеком).
 *
 * Все добавления аддитивны (ADD COLUMN NULL/DEFAULT) — нулевой прод-риск, безопасный откат кода
 * без down-миграции. MySQL не умеет ADD COLUMN IF NOT EXISTS → проверяем information_schema
 * поколоночно (идемпотентно). down() снимает добавленное DROP COLUMN.
 */
final class Version20260707_advisor_execution_fields extends AbstractMigration
{
    /** @var array<string, list<array{name: string, ddl: string}>> */
    private const COLUMNS = [
        'advisor_idea' => [
            ['name' => 'action_class', 'ddl' => "action_class VARCHAR(1) DEFAULT NULL"],
            ['name' => 'needs_human',  'ddl' => "needs_human TINYINT(1) NOT NULL DEFAULT 0"],
        ],
        'advisor_experiment' => [
            ['name' => 'stage',         'ddl' => "stage VARCHAR(32) NOT NULL DEFAULT 'pending'"],
            ['name' => 'action_class',  'ddl' => "action_class VARCHAR(1) DEFAULT NULL"],
            ['name' => 'worktree_path', 'ddl' => "worktree_path VARCHAR(255) DEFAULT NULL"],
            ['name' => 'test_status',   'ddl' => "test_status VARCHAR(16) DEFAULT NULL"],
            ['name' => 'test_report',   'ddl' => "test_report LONGTEXT DEFAULT NULL"],
            ['name' => 'gate_report',   'ddl' => "gate_report JSON DEFAULT NULL"],
            ['name' => 'pr_url',        'ddl' => "pr_url VARCHAR(255) DEFAULT NULL"],
            ['name' => 'attempts',      'ddl' => "attempts INT NOT NULL DEFAULT 0"],
            ['name' => 'failure_note',  'ddl' => "failure_note LONGTEXT DEFAULT NULL"],
            ['name' => 'approved_by',   'ddl' => "approved_by VARCHAR(64) DEFAULT NULL"],
            ['name' => 'approved_at',   'ddl' => "approved_at DATETIME DEFAULT NULL"],
        ],
    ];

    public function getDescription(): string
    {
        return 'advisor_idea.action_class/needs_human + поля гитфлоу-трекера в advisor_experiment (Фаза A советника)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if (!$this->columnExists($table, $col['name'])) {
                    $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $col['ddl']));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if ($this->columnExists($table, $col['name'])) {
                    $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $col['name']));
                }
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c",
            ['t' => $table, 'c' => $column],
        ) > 0;
    }
}
