<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Discovery → URL-очередь → Fetch (шаг 1: схема):
 *  1. brand_source_url           — DB-очередь URL для скрейпа (discover наполняет, fetch дренит)
 *  2. brand_rag_pipeline         + has_own_site + discovered_at
 *  3. brand_source_document      + relevance_score; legacy source_type official_site → own_site
 *
 * FK brand_id — INT (brand.id signed), НЕ UNSIGNED (это только для country.id).
 * Уникальный индекс по url_hash (sha256), НЕ по url: VARCHAR(1024) utf8mb4 > 3072-байт
 * лимита InnoDB (тот же урок, что content_hash).
 */
final class Version20260603_brand_source_url extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discovery URL queue: brand_source_url + has_own_site/discovered_at + relevance_score';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_source_url (
                id              INT NOT NULL AUTO_INCREMENT,
                brand_id        INT NOT NULL,
                url             VARCHAR(1024) NOT NULL,
                url_hash        CHAR(64) NOT NULL COMMENT 'sha256(нормализованный url) — дедуп',
                source_type     VARCHAR(20) NOT NULL
                                COMMENT 'own_site|marketplace|catalog|article_review|social|mention',
                tier            TINYINT NOT NULL COMMENT '1 own_site, 2 corpus, 3 mentions/social',
                relevance_score FLOAT NOT NULL DEFAULT 0,
                status          VARCHAR(12) NOT NULL DEFAULT 'pending'
                                COMMENT 'pending|claimed|fetched|failed|skipped',
                attempts        INT NOT NULL DEFAULT 0,
                last_error      TEXT DEFAULT NULL,
                discovered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                claimed_at      DATETIME DEFAULT NULL,
                fetched_at      DATETIME DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_bsu_brand_hash (brand_id, url_hash),
                INDEX idx_bsu_status_brand (status, brand_id),
                INDEX idx_bsu_brand_tier (brand_id, tier),
                CONSTRAINT fk_bsu_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // brand_rag_pipeline: has_own_site (provisional/confirmed/false → tri-state nullable bool),
        // discovered_at (когда отработал discover).
        if (!$this->columnExists('brand_rag_pipeline', 'has_own_site')) {
            $this->addSql('ALTER TABLE brand_rag_pipeline ADD has_own_site TINYINT(1) DEFAULT NULL');
        }
        if (!$this->columnExists('brand_rag_pipeline', 'discovered_at')) {
            $this->addSql('ALTER TABLE brand_rag_pipeline ADD discovered_at DATETIME DEFAULT NULL');
        }

        // brand_source_document: relevance_score (carry-forward в Qdrant payload).
        if (!$this->columnExists('brand_source_document', 'relevance_score')) {
            $this->addSql('ALTER TABLE brand_source_document ADD relevance_score FLOAT NOT NULL DEFAULT 0');
        }

        // Единая таксономия: legacy official_site → own_site.
        $this->addSql("UPDATE brand_source_document SET source_type = 'own_site' WHERE source_type = 'official_site'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE brand_source_document SET source_type = 'official_site' WHERE source_type = 'own_site'");
        if ($this->columnExists('brand_source_document', 'relevance_score')) {
            $this->addSql('ALTER TABLE brand_source_document DROP COLUMN relevance_score');
        }
        if ($this->columnExists('brand_rag_pipeline', 'discovered_at')) {
            $this->addSql('ALTER TABLE brand_rag_pipeline DROP COLUMN discovered_at');
        }
        if ($this->columnExists('brand_rag_pipeline', 'has_own_site')) {
            $this->addSql('ALTER TABLE brand_rag_pipeline DROP COLUMN has_own_site');
        }
        $this->addSql('DROP TABLE IF EXISTS brand_source_url');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column],
        );
    }
}
