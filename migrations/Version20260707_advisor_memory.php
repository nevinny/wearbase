<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Модель памяти бизнес-советника (docs/advisor.md §«Модель памяти», Phase 1 MVP):
 *  - state_snapshot     — KPI-вектор проекта на момент tick'а + пофилдовая дельта к предыдущему.
 *  - advisor_idea       — бэклог гипотез: RAG-provenance, ICE-скор, статус, dedupe (докстрока §Дедуп).
 *  - advisor_run        — аудит каждого tick'а (входы/дайджест/решения).
 *  - advisor_experiment — идея → ветка/деплой → baseline (state_snapshot) → окно замера → вердикт
 *                          (форма скопирована по смыслу с brand_content_revision, но на уровне проекта).
 * Все FK на обычные INT PK (без country.id) — INT UNSIGNED здесь не нужен.
 */
final class Version20260707_advisor_memory extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'state_snapshot/advisor_idea/advisor_run/advisor_experiment — модель памяти бизнес-советника (docs/advisor.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS state_snapshot (
                id INT AUTO_INCREMENT NOT NULL,
                created_at DATETIME NOT NULL,
                metrics JSON NOT NULL,
                delta JSON DEFAULT NULL,
                INDEX idx_state_snapshot_created_at (created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS advisor_idea (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(255) NOT NULL,
                hypothesis LONGTEXT NOT NULL,
                source_signal VARCHAR(255) DEFAULT NULL,
                rag_citations JSON DEFAULT NULL,
                impact SMALLINT NOT NULL,
                confidence SMALLINT NOT NULL,
                ease SMALLINT NOT NULL,
                ice_score INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'proposed',
                dedupe_hash VARCHAR(64) NOT NULL,
                embedding_ref VARCHAR(128) DEFAULT NULL,
                rejected_reason LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_advisor_idea_status (status),
                INDEX idx_advisor_idea_dedupe_hash (dedupe_hash),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS advisor_run (
                id INT AUTO_INCREMENT NOT NULL,
                ran_at DATETIME NOT NULL,
                mode VARCHAR(16) NOT NULL,
                inputs_summary LONGTEXT DEFAULT NULL,
                digest_text LONGTEXT DEFAULT NULL,
                decisions JSON DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_advisor_run_ran_at (ran_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS advisor_experiment (
                id INT AUTO_INCREMENT NOT NULL,
                idea_id INT NOT NULL,
                baseline_snapshot_id INT DEFAULT NULL,
                branch VARCHAR(255) NOT NULL,
                commit_sha VARCHAR(64) DEFAULT NULL,
                deployed_at DATETIME DEFAULT NULL,
                measure_window_days INT NOT NULL DEFAULT 7,
                verdict VARCHAR(32) DEFAULT NULL,
                learning LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_advisor_experiment_idea (idea_id),
                INDEX idx_advisor_experiment_baseline (baseline_snapshot_id),
                CONSTRAINT fk_advisor_experiment_idea FOREIGN KEY (idea_id) REFERENCES advisor_idea (id),
                CONSTRAINT fk_advisor_experiment_baseline FOREIGN KEY (baseline_snapshot_id) REFERENCES state_snapshot (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS advisor_experiment');
        $this->addSql('DROP TABLE IF EXISTS advisor_run');
        $this->addSql('DROP TABLE IF EXISTS advisor_idea');
        $this->addSql('DROP TABLE IF EXISTS state_snapshot');
    }
}
