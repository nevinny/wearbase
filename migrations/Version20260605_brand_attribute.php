<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Структурированные атрибуты бренда из краула (LLM-extract): материалы, gender,
 * ценовой сегмент, гео, размерный ряд — то, что не ложится в существующие
 * справочники BrandStyle/BrandSize/ProductCategory. Лёгкий EAV + provenance
 * (enrichment|owner|crowd_confirmed) для краудсорс-валидации.
 *  + attributes_status/extracted_at на pipeline (стадия extract).
 */
final class Version20260605_brand_attribute extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_attribute (EAV) + pipeline attributes_status/extracted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_attribute (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                name VARCHAR(40) NOT NULL COMMENT 'gender|material|price_segment|geo|size_range',
                value VARCHAR(255) NOT NULL,
                provenance VARCHAR(16) NOT NULL DEFAULT 'enrichment',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_battr (brand_id, name, value),
                INDEX idx_battr_brand (brand_id),
                CONSTRAINT fk_battr_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE brand_rag_pipeline
                ADD attributes_status VARCHAR(12) DEFAULT NULL COMMENT 'NULL=не извлекали|done|skipped|failed',
                ADD extracted_at DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_attribute');
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP attributes_status, DROP extracted_at');
    }
}
