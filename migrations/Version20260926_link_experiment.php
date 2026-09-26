<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/hadi_orphan_links.md — рандомизированный эксперимент с сиротами графа перелинковки.
 *
 * link_experiment: участник эксперимента (бренд → группа T/C) + снимок базовой линии GSC.
 *   Пока ended_at IS NULL, контрольная группа заморожена: BrandLinkGraphService не даёт
 *   ей входящих рёбер (addEdges/replaceDeadEdges), push из агент-API — тоже.
 * link_experiment_edge: журнал рёбер, добавленных экспериментом (что стояло в слоте до —
 *   old_target_id/old_source), чтобы откат был запросом, а не археологией.
 */
final class Version20260926_link_experiment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'link_experiment + link_experiment_edge — HADI-эксперимент с сиротами графа перелинковки';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS link_experiment (
                id INT AUTO_INCREMENT NOT NULL,
                experiment VARCHAR(40) NOT NULL,
                brand_id INT NOT NULL,
                arm VARCHAR(10) NOT NULL COMMENT 'treatment | control',
                baseline_state VARCHAR(80) DEFAULT NULL COMMENT 'GSC coverage_state на момент назначения',
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                verdict VARCHAR(20) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_link_exp_brand (experiment, brand_id),
                INDEX idx_link_exp_arm (arm, ended_at),
                CONSTRAINT fk_link_exp_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS link_experiment_edge (
                id INT AUTO_INCREMENT NOT NULL,
                experiment VARCHAR(40) NOT NULL,
                donor_id INT NOT NULL,
                target_id INT NOT NULL,
                position SMALLINT NOT NULL,
                tier VARCHAR(10) NOT NULL COMMENT 'free | fill | orphan — откуда взят слот донора',
                score DECIMAL(5,3) DEFAULT NULL COMMENT 'косинус эмбеддингов донор↔таргет',
                old_target_id INT DEFAULT NULL COMMENT 'кого вытеснили из слота (NULL = слот был свободен)',
                old_source VARCHAR(20) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                reverted_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_link_exp_edge (experiment, donor_id, target_id),
                INDEX idx_link_exp_edge_donor (donor_id),
                INDEX idx_link_exp_edge_target (target_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS link_experiment_edge');
        $this->addSql('DROP TABLE IF EXISTS link_experiment');
    }
}
