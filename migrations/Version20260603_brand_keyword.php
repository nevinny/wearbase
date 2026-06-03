<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ключевики бренда (Wordstat) — отдельная таблица, собирается заранее
 * (app:brand:keywords), генерация читает готовое. type: origin|related,
 * monthly_shows — показов в месяц по региону. В Qdrant НЕ кладётся.
 */
final class Version20260603_brand_keyword extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_keyword table: cached Wordstat keywords per brand (origin/related + monthly shows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_keyword (
                id            INT NOT NULL AUTO_INCREMENT,
                brand_id      INT NOT NULL,
                keyword       VARCHAR(255) NOT NULL,
                type          VARCHAR(16) NOT NULL DEFAULT 'origin' COMMENT 'origin|related',
                monthly_shows INT DEFAULT NULL COMMENT 'показов в месяц (Wordstat) по региону',
                region        INT DEFAULT 225,
                source        VARCHAR(16) NOT NULL DEFAULT 'wordstat' COMMENT 'wordstat|llm',
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_bkw_brand (brand_id),
                UNIQUE INDEX uniq_bkw_brand_phrase_type (brand_id, keyword, type),
                CONSTRAINT fk_bkw_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_keyword');
    }
}
