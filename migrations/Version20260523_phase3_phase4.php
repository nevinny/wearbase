<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 — Переводы контента: brand_translation, product_translation
 * Phase 4 — Правила доставки и налогов: shipping_rule, tax_rule
 */
final class Version20260523_phase3_phase4 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3: content translations (brand, product). Phase 4: shipping_rule, tax_rule';
    }

    public function up(Schema $schema): void
    {
        // ── Phase 3: brand_translation ──────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_translation (
                id              INT AUTO_INCREMENT NOT NULL,
                brand_id        INT NOT NULL,
                locale          VARCHAR(5) NOT NULL,
                title           VARCHAR(255) DEFAULT NULL,
                anons           LONGTEXT DEFAULT NULL,
                description     LONGTEXT DEFAULT NULL,
                meta_title      VARCHAR(255) DEFAULT NULL,
                meta_description VARCHAR(500) DEFAULT NULL,
                updated_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE KEY uq_brand_translation (brand_id, locale),
                KEY idx_brand_translation_brand (brand_id),
                CONSTRAINT fk_brand_translation_brand
                    FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── Phase 3: product_translation ────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_translation (
                id              INT AUTO_INCREMENT NOT NULL,
                product_id      INT NOT NULL,
                locale          VARCHAR(5) NOT NULL,
                title           VARCHAR(255) DEFAULT NULL,
                anons           VARCHAR(500) DEFAULT NULL,
                description     LONGTEXT DEFAULT NULL,
                meta_title      VARCHAR(255) DEFAULT NULL,
                meta_description VARCHAR(500) DEFAULT NULL,
                updated_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE KEY uq_product_translation (product_id, locale),
                KEY idx_product_translation_product (product_id),
                CONSTRAINT fk_product_translation_product
                    FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── Phase 4: shipping_rule ───────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS shipping_rule (
                id                  INT AUTO_INCREMENT NOT NULL,
                country_id          INT UNSIGNED NOT NULL,
                carrier             VARCHAR(30) NOT NULL DEFAULT 'cdek',
                name                VARCHAR(80) NOT NULL,
                price_rub           DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                days_min            INT NOT NULL DEFAULT 1,
                days_max            INT NOT NULL DEFAULT 7,
                max_weight_kg       DECIMAL(6,2) DEFAULT NULL,
                free_from_rub       DECIMAL(10,2) DEFAULT NULL,
                tracking_url        VARCHAR(255) DEFAULT NULL,
                is_active           TINYINT(1) NOT NULL DEFAULT 1,
                sort_order          INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_shipping_country (country_id),
                CONSTRAINT fk_shipping_rule_country
                    FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── Phase 4: tax_rule ────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tax_rule (
                id                      INT AUTO_INCREMENT NOT NULL,
                country_id              INT UNSIGNED NOT NULL,
                name                    VARCHAR(120) NOT NULL,
                tax_type                VARCHAR(20) NOT NULL DEFAULT 'vat',
                rate                    DECIMAL(5,2) NOT NULL DEFAULT '0.00',
                customs_rate            DECIMAL(5,2) NOT NULL DEFAULT '0.00',
                customs_threshold_rub   DECIMAL(10,2) DEFAULT NULL,
                is_inclusive            TINYINT(1) NOT NULL DEFAULT 0,
                applies_to_b2c          TINYINT(1) NOT NULL DEFAULT 1,
                applies_to_b2b          TINYINT(1) NOT NULL DEFAULT 0,
                source_url              VARCHAR(255) DEFAULT NULL,
                is_active               TINYINT(1) NOT NULL DEFAULT 1,
                sort_order              INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_tax_country (country_id),
                CONSTRAINT fk_tax_rule_country
                    FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── Seed: shipping_rule ──────────────────────────────────────────────────
        // Россия: СДЭК, Почта России, Boxberry, Курьер по Москве
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'cdek', 'СДЭК', 350.00, 2, 5, 5000.00,
                   'https://www.cdek.ru/ru/tracking/?order_id=%s', 1, 10
            FROM country c WHERE c.code = 'RU'
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'pochta', 'Почта России', 250.00, 5, 14, 10000.00,
                   'https://www.pochta.ru/tracking#%s', 1, 20
            FROM country c WHERE c.code = 'RU'
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'boxberry', 'Boxberry', 300.00, 2, 6, 5000.00,
                   'https://boxberry.ru/tracking/?id=%s', 1, 30
            FROM country c WHERE c.code = 'RU'
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'courier', 'Курьер по Москве', 500.00, 1, 2, 7000.00, NULL, 1, 5
            FROM country c WHERE c.code = 'RU'
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, is_active, sort_order)
            SELECT c.id, 'pickup', 'Самовывоз', 0.00, 1, 1, 1, 1
            FROM country c WHERE c.code = 'RU'
        SQL);

        // Беларусь: СДЭК
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, tracking_url, is_active, sort_order)
            SELECT c.id, 'cdek', 'СДЭК', 600.00, 3, 7,
                   'https://www.cdek.ru/ru/tracking/?order_id=%s', 1, 10
            FROM country c WHERE c.code = 'BY'
        SQL);

        // Казахстан: СДЭК
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, tracking_url, is_active, sort_order)
            SELECT c.id, 'cdek', 'СДЭК', 700.00, 3, 8,
                   'https://www.cdek.ru/ru/tracking/?order_id=%s', 1, 10
            FROM country c WHERE c.code = 'KZ'
        SQL);

        // Германия: DHL, DPD
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'dhl', 'DHL Express', 2500.00, 5, 10, 20000.00,
                   'https://www.dhl.com/en/express/tracking.html?AWB=%s', 1, 10
            FROM country c WHERE c.code = 'DE'
        SQL);

        // Франция: DHL
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'dhl', 'DHL Express', 2700.00, 5, 12, 20000.00,
                   'https://www.dhl.com/en/express/tracking.html?AWB=%s', 1, 10
            FROM country c WHERE c.code = 'FR'
        SQL);

        // Великобритания: FedEx
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'fedex', 'FedEx International', 3000.00, 5, 12, 25000.00,
                   'https://www.fedex.com/apps/fedextrack/?tracknumbers=%s', 1, 10
            FROM country c WHERE c.code = 'GB'
        SQL);

        // ОАЭ: DHL
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, free_from_rub, tracking_url, is_active, sort_order)
            SELECT c.id, 'dhl', 'DHL Express', 2200.00, 4, 8, 15000.00,
                   'https://www.dhl.com/en/express/tracking.html?AWB=%s', 1, 10
            FROM country c WHERE c.code = 'AE'
        SQL);

        // Турция: DHL
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO shipping_rule (country_id, carrier, name, price_rub, days_min, days_max, tracking_url, is_active, sort_order)
            SELECT c.id, 'dhl', 'DHL Express', 2000.00, 4, 8,
                   'https://www.dhl.com/en/express/tracking.html?AWB=%s', 1, 10
            FROM country c WHERE c.code = 'TR'
        SQL);

        // ── Seed: tax_rule ───────────────────────────────────────────────────────
        // Россия — НДС 20%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Россия 20%', 'vat', 20.00, 0.00, 1, 1, 1,
                   'https://www.nalog.gov.ru', 1, 10
            FROM country c WHERE c.code = 'RU'
        SQL);

        // Германия — НДС 19%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, customs_threshold_rub, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Германия 19%', 'vat', 19.00, 12.00, 5000.00, 0, 1, 0,
                   'https://www.bundesfinanzministerium.de', 1, 10
            FROM country c WHERE c.code = 'DE'
        SQL);

        // Франция — НДС 20%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, customs_threshold_rub, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Франция 20%', 'vat', 20.00, 12.00, 5000.00, 0, 1, 0,
                   'https://www.impots.gouv.fr', 1, 10
            FROM country c WHERE c.code = 'FR'
        SQL);

        // Великобритания — НДС 20%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, customs_threshold_rub, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Великобритания 20%', 'vat', 20.00, 12.00, 8000.00, 0, 1, 0,
                   'https://www.gov.uk/vat-rates', 1, 10
            FROM country c WHERE c.code = 'GB'
        SQL);

        // ОАЭ — НДС 5%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС ОАЭ 5%', 'vat', 5.00, 5.00, 0, 1, 1,
                   'https://tax.gov.ae', 1, 10
            FROM country c WHERE c.code = 'AE'
        SQL);

        // Турция — НДС 20%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Турция 20%', 'vat', 20.00, 18.00, 0, 1, 1,
                   'https://www.gib.gov.tr', 1, 10
            FROM country c WHERE c.code = 'TR'
        SQL);

        // Казахстан — НДС 12%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Казахстан 12%', 'vat', 12.00, 0.00, 1, 1, 1,
                   'https://kgd.gov.kz', 1, 10
            FROM country c WHERE c.code = 'KZ'
        SQL);

        // Беларусь — НДС 20%
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tax_rule (country_id, name, tax_type, rate, customs_rate, is_inclusive, applies_to_b2c, applies_to_b2b, source_url, is_active, sort_order)
            SELECT c.id, 'НДС Беларусь 20%', 'vat', 20.00, 0.00, 1, 1, 1,
                   'https://www.nalog.gov.by', 1, 10
            FROM country c WHERE c.code = 'BY'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tax_rule');
        $this->addSql('DROP TABLE IF EXISTS shipping_rule');
        $this->addSql('DROP TABLE IF EXISTS product_translation');
        $this->addSql('DROP TABLE IF EXISTS brand_translation');
    }
}
