<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Google Search Console (дизайн «аналитика бренда + GSC», tasktracker 2026-06-04):
 *  - gsc_page_stats:   дневные показы/клики/позиция по страницам (Search Analytics,
 *                      brand_id резолвится по slug без локали — суммирует 9 локалей)
 *  - gsc_index_status: ТЕКУЩЕЕ покрытие индекса по бренду (URL Inspection,
 *                      одна строка на бренд, перезаписывается)
 */
final class Version20260604_gsc extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gsc_page_stats + gsc_index_status — данные Search Console';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gsc_page_stats (
                id INT AUTO_INCREMENT NOT NULL,
                page_url VARCHAR(512) NOT NULL,
                brand_id INT DEFAULT NULL COMMENT 'резолв по slug; NULL для не-брендовых страниц',
                day DATE NOT NULL,
                impressions INT NOT NULL DEFAULT 0,
                clicks INT NOT NULL DEFAULT 0,
                position DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                query VARCHAR(255) DEFAULT NULL COMMENT 'NULL = агрегат по странице',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_gsc (page_url(191), day, query),
                INDEX idx_gsc_brand (brand_id, day),
                CONSTRAINT fk_gsc_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gsc_index_status (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                page_url VARCHAR(512) NOT NULL,
                coverage_state VARCHAR(80) DEFAULT NULL,
                indexed TINYINT(1) NOT NULL DEFAULT 0,
                last_checked_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_idx (brand_id),
                INDEX idx_idx_checked (last_checked_at),
                CONSTRAINT fk_idx_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gsc_index_status');
        $this->addSql('DROP TABLE IF EXISTS gsc_page_stats');
    }
}
