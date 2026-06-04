<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Краудсорс-валидация данных бренда («исправить неточность» + голоса ✓/✗):
 *  - brand_datapoint:      состояние точки данных (полиморфно: контакты/link/store),
 *                          provenance enrichment|owner|crowd_confirmed,
 *                          state active|doubtful|hidden|pinned, строки ленивые
 *  - brand_datapoint_vote: голоса с дедупом по voter_hash (sha256, без PII)
 * Дизайн: tasktracker «Архитектура: краудсорс-валидация данных бренда».
 */
final class Version20260604_brand_datapoint extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_datapoint + brand_datapoint_vote — краудсорс-валидация данных';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_datapoint (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                target_type VARCHAR(20) NOT NULL COMMENT 'brand_contact|brand_link|brand_store',
                target_id INT DEFAULT NULL COMMENT 'id строки link/store; NULL для скаляров brand',
                field VARCHAR(20) NOT NULL COMMENT 'phone|email|address|url|workhours',
                provenance VARCHAR(16) NOT NULL DEFAULT 'enrichment' COMMENT 'enrichment|owner|crowd_confirmed',
                state VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active|doubtful|hidden|pinned',
                confirm_count INT NOT NULL DEFAULT 0,
                reject_count INT NOT NULL DEFAULT 0,
                reject_window INT NOT NULL DEFAULT 0,
                owner_edited_at DATETIME DEFAULT NULL,
                state_changed_at DATETIME DEFAULT NULL,
                queued_revalidate_at DATETIME DEFAULT NULL,
                revalidated_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_datapoint (brand_id, target_type, target_id, field),
                INDEX idx_dp_brand (brand_id),
                INDEX idx_dp_queue (queued_revalidate_at),
                CONSTRAINT fk_dp_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_datapoint_vote (
                id INT AUTO_INCREMENT NOT NULL,
                datapoint_id INT NOT NULL,
                vote VARCHAR(8) NOT NULL COMMENT 'confirm|reject',
                suggestion VARCHAR(500) DEFAULT NULL,
                voter_hash CHAR(64) NOT NULL COMMENT 'sha256(ip+daily_salt+UA), без PII',
                user_id INT DEFAULT NULL,
                weight SMALLINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_vote (datapoint_id, voter_hash),
                INDEX idx_vote_dp (datapoint_id),
                CONSTRAINT fk_vote_dp FOREIGN KEY (datapoint_id) REFERENCES brand_datapoint (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_datapoint_vote');
        $this->addSql('DROP TABLE IF EXISTS brand_datapoint');
    }
}
