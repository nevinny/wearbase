<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_content_revision — append-only история контента бренда (description+meta-тройка)
 * и журнал closed-loop экспериментов (baseline GSC → окно 14д → win/loss/откат).
 *
 * Live-значения остаются в brand.* (read-path сайта не трогаем); активная ревизия их зеркалит.
 * Промоутим ТОЛЬКО версии, прошедшие quality-gate (ContentValidator + grounding).
 */
final class Version20260614_brand_content_revision extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_content_revision — версии контента + журнал closed-loop экспериментов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_content_revision (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                description LONGTEXT DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description VARCHAR(500) DEFAULT NULL,
                source VARCHAR(20) NOT NULL COMMENT 'legacy|rag|manual|import|rollback',
                grounded TINYINT(1) NOT NULL DEFAULT 0,
                retrieval_score DOUBLE PRECISION DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                attempt INT NOT NULL DEFAULT 1 COMMENT 'номер попытки регенерации в цепочке эксперимента',
                prev_revision_id INT DEFAULT NULL COMMENT 'версия, которую заменила (цель отката)',
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                measure_after DATETIME DEFAULT NULL COMMENT 'когда оценивать closed-loop (старт + окно)',
                verdict VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|win|loss|neutral|not_indexed',
                gsc_impr_before INT DEFAULT NULL,
                gsc_clicks_before INT DEFAULT NULL,
                gsc_indexed_before TINYINT(1) DEFAULT NULL,
                gsc_impr_after INT DEFAULT NULL,
                gsc_clicks_after INT DEFAULT NULL,
                gsc_indexed_after TINYINT(1) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_bcr_brand (brand_id),
                INDEX idx_bcr_active (brand_id, is_active),
                INDEX idx_bcr_eval (verdict, measure_after),
                CONSTRAINT fk_bcr_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_content_revision');
    }
}
