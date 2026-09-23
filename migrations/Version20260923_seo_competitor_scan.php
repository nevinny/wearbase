<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/seo_competitor_content.md — разведка конкурентов в выдаче → gap-контекст.
 *
 * competitor_article: кэш скрейпнутого контента по URL (одна статья может встретиться
 * под несколько SEO-фраз — не перескрейпим, см. 30-дневный кэш в app:seo:competitor-scan).
 *
 * seo_competitor_scan: одна строка на проверку SEO-фразы — SERP конкурентов + извлечённые
 * темы/структуру (gap_summary, НЕ факты конкурента) + приоритет + статус-машина конвейера.
 */
final class Version20260923_seo_competitor_scan extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'competitor_article + seo_competitor_scan — разведка конкурентов в выдаче (gap-контекст)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS competitor_article (
                id INT AUTO_INCREMENT NOT NULL,
                url VARCHAR(768) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                page_type VARCHAR(20) NOT NULL DEFAULT 'other',
                title VARCHAR(255) DEFAULT NULL,
                content LONGTEXT DEFAULT NULL,
                word_count INT DEFAULT NULL,
                http_status INT DEFAULT NULL,
                fetched_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_competitor_article_url (url)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seo_competitor_scan (
                id INT AUTO_INCREMENT NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                demand_source VARCHAR(16) NOT NULL,
                intent_group VARCHAR(30) NOT NULL,
                our_url VARCHAR(512) DEFAULT NULL,
                serp_results JSON NOT NULL,
                gap_summary LONGTEXT DEFAULT NULL,
                priority_score DOUBLE PRECISION DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                checked_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_seo_competitor_scan_keyword (keyword)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS seo_competitor_scan');
        $this->addSql('DROP TABLE IF EXISTS competitor_article');
    }
}
