<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_moderation — MVP авто-премодерации самрег-брендов (docs/tasktracker.md).
 * Одна строка на бренд (unique brand_id): создаётся при регистрации (status=queued),
 * дополняется агент-конвейером Mac (app:brand:moderate-tick → POST /api/v1/moderation/verdict,
 * status=reviewed) и решением администратора по TG-кнопке (BrandModerationController →
 * approved|changes_requested|rejected). brand.id — обычный signed INT (не UNSIGNED,
 * см. CLAUDE.md), FK-колонка того же типа.
 */
final class Version20260730_brand_moderation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_moderation — очередь премодерации самрег-брендов + вердикт агент-конвейера';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_moderation (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                source VARCHAR(20) NOT NULL COMMENT 'self_register|claim|manual',
                status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued|reviewed|approved|changes_requested|rejected',
                verdict VARCHAR(20) DEFAULT NULL COMMENT 'publish|request_changes|reject',
                identity_match VARCHAR(20) DEFAULT NULL COMMENT 'confirmed|weak|unconfirmed|no_trace',
                control_proof VARCHAR(20) DEFAULT NULL COMMENT 'confirmed|unconfirmed',
                evidence JSON DEFAULT NULL COMMENT '[{url,score,matched:{phone,email,address,title}}]',
                red_flags JSON DEFAULT NULL,
                missing JSON DEFAULT NULL COMMENT 'logo,price,inn,founding_year,production_place,description,links',
                summary LONGTEXT DEFAULT NULL,
                analyze_attempts INT NOT NULL DEFAULT 0,
                analyzed_at DATETIME DEFAULT NULL,
                decided_at DATETIME DEFAULT NULL,
                decided_via VARCHAR(10) DEFAULT NULL COMMENT 'tg|admin',
                admin_note LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_brand_moderation_brand (brand_id),
                INDEX idx_brand_moderation_status (status),
                CONSTRAINT FK_brand_moderation_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_moderation');
    }
}
