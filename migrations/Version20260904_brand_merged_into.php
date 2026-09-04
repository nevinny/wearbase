<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand.merged_into_id — указатель «этот бренд склеен в другой».
 *
 * Зачем: лид-импорты завели один реальный бренд двумя записями (первый случай —
 * murkafam / aleksandr-murka, обе страницы отдавали 200). Для дубля правильный
 * сигнал — 301 на выжившую карточку, а не 410: 410 выбрасывает и внутренние
 * ссылки, и накопленные сигналы. Существующего механизма редиректов у каталога
 * не было (closed_at — это «бренд закрылся», страница остаётся 200 с плашкой).
 */
final class Version20260904_brand_merged_into extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.merged_into_id — дубль бренда 301-ит на выжившую карточку';
    }

    public function up(Schema $schema): void
    {
        // IF NOT EXISTS у ADD COLUMN в MySQL нет — проверяем словарь (миграции идемпотентны).
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'brand' AND COLUMN_NAME = 'merged_into_id'"
        );

        if ($exists === 0) {
            $this->addSql('ALTER TABLE brand ADD merged_into_id INT DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_brand_merged_into ON brand (merged_into_id)');
            $this->addSql(
                'ALTER TABLE brand ADD CONSTRAINT fk_brand_merged_into
                 FOREIGN KEY (merged_into_id) REFERENCES brand (id) ON DELETE SET NULL'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand DROP FOREIGN KEY fk_brand_merged_into');
        $this->addSql('DROP INDEX idx_brand_merged_into ON brand');
        $this->addSql('ALTER TABLE brand DROP merged_into_id');
    }
}
