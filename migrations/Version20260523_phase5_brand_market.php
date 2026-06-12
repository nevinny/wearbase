<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 — Витрины по странам.
 * Таблица brand_market: присутствие бренда на конкретном рынке (стране).
 */
final class Version20260523_phase5_brand_market extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5: brand_market — brand presence per country/market';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_market (
                id                      INT AUTO_INCREMENT NOT NULL,
                brand_id                INT NOT NULL,
                country_id              INT UNSIGNED NOT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'active',
                has_local_warehouse     TINYINT(1) NOT NULL DEFAULT 0,
                custom_shipping_rub     DECIMAL(10,2) DEFAULT NULL,
                free_shipping_from_rub  DECIMAL(10,2) DEFAULT NULL,
                estimated_days          INT DEFAULT NULL,
                active_from             DATE DEFAULT NULL,
                sort_order              INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_brand_market (brand_id, country_id),
                KEY idx_brand_market_country (country_id),
                CONSTRAINT fk_brand_market_brand
                    FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE,
                CONSTRAINT fk_brand_market_country
                    FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_market');
    }
}
