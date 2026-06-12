<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляет:
 *  1. Поля отслеживания обогащения в таблицу `brand`
 *  2. Поле `link_type` в `brand_link`
 *  3. Таблицу `brand_store` для физических точек продаж
 *
 * NOTE: MySQL не поддерживает ADD COLUMN IF NOT EXISTS — идемпотентность
 * обеспечивается системой миграций (каждая миграция запускается ровно один раз).
 */
final class Version20260524_brand_contact_enrichment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Brand contact enrichment: tracking fields + brand_link.link_type + brand_store table';
    }

    public function up(Schema $schema): void
    {
        // 1. Поля обогащения в brand
        $this->addSql(<<<'SQL'
            ALTER TABLE brand
                ADD COLUMN contact_enriched_at DATETIME DEFAULT NULL COMMENT 'Время последнего запуска обогащения',
                ADD COLUMN contact_status      VARCHAR(20) DEFAULT NULL COMMENT 'enriched|partial|not_found|error',
                ADD COLUMN contact_attempts    INT NOT NULL DEFAULT 0  COMMENT 'Количество попыток обогащения'
        SQL);

        // 2. Тип ссылки в brand_link
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_link
                ADD COLUMN link_type VARCHAR(32) DEFAULT NULL COMMENT 'website|instagram|vk|telegram|youtube|tiktok|other'
        SQL);

        // 3. Таблица brand_store
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_store (
                id             INT NOT NULL AUTO_INCREMENT,
                brand_id       INT NOT NULL,
                address        VARCHAR(500) NOT NULL,
                city           VARCHAR(100) DEFAULT NULL,
                phone          VARCHAR(30)  DEFAULT NULL,
                work_hours     VARCHAR(255) DEFAULT NULL,
                source         VARCHAR(20)  NOT NULL DEFAULT 'enrichment',
                status         VARCHAR(20)  NOT NULL DEFAULT 'active',
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_brand_store_brand_id (brand_id),
                CONSTRAINT fk_brand_store_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_store');

        $this->addSql('ALTER TABLE brand_link DROP COLUMN link_type');

        $this->addSql(<<<'SQL'
            ALTER TABLE brand
                DROP COLUMN contact_enriched_at,
                DROP COLUMN contact_status,
                DROP COLUMN contact_attempts
        SQL);
    }
}
