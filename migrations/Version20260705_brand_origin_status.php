<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Origin-гейт (docs/foreign_brands_policy.md — иностранные бренды не публикуем):
 *  - origin_status / origin_reason / origin_checked_at — вердикт классификатора
 *    происхождения (app:brand:origin-check). NULL=не проверен, 'ru'=российский,
 *    'foreign'=иностранный/глобальный (Nike, Chanel…), 'unknown'=сомнение → ручной
 *    review. 'foreign' и 'unknown' гейтят конвейер и дрип-публикацию; NULL проходит.
 */
final class Version20260705_brand_origin_status extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.origin_status/origin_reason/origin_checked_at — origin-гейт иностранных брендов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand ADD COLUMN origin_status VARCHAR(12) DEFAULT NULL, ADD COLUMN origin_reason VARCHAR(255) DEFAULT NULL, ADD COLUMN origin_checked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_brand_origin ON brand (origin_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_brand_origin ON brand');
        $this->addSql('ALTER TABLE brand DROP COLUMN origin_status, DROP COLUMN origin_reason, DROP COLUMN origin_checked_at');
    }
}
