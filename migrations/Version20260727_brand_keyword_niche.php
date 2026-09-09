<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_keyword.niche_status/niche_checked_at — вердикт классификатора ниши
 * ПРО КОНКРЕТНУЮ ФРАЗУ (не про бренд-владельца): app:brand:keyword-niche-check.
 * NULL — не проверена (fail-open, трактуется как проходит); 'in' — фраза про
 * моду/красоту; 'off' — не про нишу (яндекс, техника, аптека и т.п.). Импорт
 * лидов и Wordstat подмешивают мусорные фразы даже у нишевых брендов
 * («яндекс погода» как related-запрос) — режем только явный 'off' в
 * потребителях контента (findByBrandRanked/findTopByBrand), NULL проходит.
 *
 * Идемпотентно: ADD COLUMN только если столбца ещё нет
 * (MySQL не поддерживает ADD COLUMN IF NOT EXISTS).
 */
final class Version20260727_brand_keyword_niche extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_keyword.niche_status/niche_checked_at — вердикт ниши по фразе (не по бренду)';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'brand_keyword' AND column_name = 'niche_status'",
        );
        if ($exists === 0) {
            $this->addSql('ALTER TABLE brand_keyword ADD COLUMN niche_status VARCHAR(12) DEFAULT NULL, ADD COLUMN niche_checked_at DATETIME DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_bkw_niche ON brand_keyword (niche_status)');
        }
    }

    public function down(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'brand_keyword' AND column_name = 'niche_status'",
        );
        if ($exists > 0) {
            $this->addSql('DROP INDEX idx_bkw_niche ON brand_keyword');
            $this->addSql('ALTER TABLE brand_keyword DROP COLUMN niche_status, DROP COLUMN niche_checked_at');
        }
    }
}
