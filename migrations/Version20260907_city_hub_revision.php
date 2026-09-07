<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * city_hub_revision — append-only история контента городского хаба + журнал
 * closed-loop эксперимента (аналог brand_content_revision, но измеримое
 * подмножество полей — без grounded/retrieval_score/loss_streak, они бренд-специфичны).
 *
 * Зачем: абсолютный QA-балл не годится как гейт качества коротких intro хабов —
 * его провалили бы все 4 живых хаба, включая тот, что реально собирает показы
 * (docs/geo_city_demand_2026_09.md §8). Настоящий гейт — исход по GSC/Яндексу
 * с возможностью откатить, тот же паттерн, что уже работает для контента бренда.
 */
final class Version20260907_city_hub_revision extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'city_hub_revision — версии контента хаба + журнал closed-loop эксперимента';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS city_hub_revision (
                id INT AUTO_INCREMENT NOT NULL,
                city_hub_id INT NOT NULL,
                slug VARCHAR(255) NOT NULL,
                h1 VARCHAR(255) DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description VARCHAR(500) DEFAULT NULL,
                intro LONGTEXT DEFAULT NULL,
                faq JSON DEFAULT NULL,
                source VARCHAR(20) NOT NULL COMMENT 'generated|manual|rollback',
                qa_overall DOUBLE PRECISION DEFAULT NULL COMMENT 'ArticleQaService overall на момент генерации',
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
                PRIMARY KEY (id),
                INDEX idx_chr_hub (city_hub_id),
                INDEX idx_chr_active (city_hub_id, is_active),
                INDEX idx_chr_eval (verdict, measure_after),
                CONSTRAINT fk_chr_hub FOREIGN KEY (city_hub_id) REFERENCES city_hub (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS city_hub_revision');
    }
}
