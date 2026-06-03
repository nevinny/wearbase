<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RAG-пайплайн генерации контента брендов:
 *  1. brand_rag_pipeline       — статус-машина этапов (scrape/embed/generate)
 *  2. brand_source_document    — скрейпленный текст + провенанс (источник эмбеддингов)
 *
 * FK brand_id — INT (brand.id signed), НЕ UNSIGNED (это только для country.id).
 */
final class Version20260603_brand_rag_pipeline extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RAG pipeline: brand_rag_pipeline state machine + brand_source_document storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_rag_pipeline (
                id                  INT NOT NULL AUTO_INCREMENT,
                brand_id            INT NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                                    COMMENT 'pending|scraped|embedded|generated|done|scrape_failed|embed_failed|generate_failed',
                scraped_at          DATETIME DEFAULT NULL,
                embedded_at         DATETIME DEFAULT NULL,
                generated_at        DATETIME DEFAULT NULL,
                scrape_attempts     INT NOT NULL DEFAULT 0,
                embed_attempts      INT NOT NULL DEFAULT 0,
                generate_attempts   INT NOT NULL DEFAULT 0,
                source_count        INT NOT NULL DEFAULT 0,
                top_retrieval_score DOUBLE PRECISION DEFAULT NULL,
                grounded            TINYINT(1) NOT NULL DEFAULT 0,
                last_error          TEXT DEFAULT NULL,
                created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_brand_rag_brand (brand_id),
                INDEX idx_brand_rag_status (status),
                CONSTRAINT fk_brand_rag_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_source_document (
                id            INT NOT NULL AUTO_INCREMENT,
                brand_id      INT NOT NULL,
                url           VARCHAR(1024) NOT NULL,
                source_type   VARCHAR(20) NOT NULL DEFAULT 'official_site'
                              COMMENT 'official_site|social|meta',
                http_status   INT DEFAULT NULL,
                content_hash  VARCHAR(64) NOT NULL COMMENT 'sha256(clean_text) — дедуп/skip-unchanged',
                raw_text      LONGTEXT DEFAULT NULL,
                clean_text    LONGTEXT DEFAULT NULL,
                char_count    INT NOT NULL DEFAULT 0,
                keywords      TEXT DEFAULT NULL,
                embedded      TINYINT(1) NOT NULL DEFAULT 0,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_bsd_brand (brand_id),
                UNIQUE INDEX uniq_bsd_brand_hash (brand_id, content_hash),
                CONSTRAINT fk_bsd_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_source_document');
        $this->addSql('DROP TABLE IF EXISTS brand_rag_pipeline');
    }
}
