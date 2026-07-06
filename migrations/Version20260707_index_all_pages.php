<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индексный трекинг всех страниц, не только брендов (yandex_index_status + gsc_index_status):
 *  - brand_id → NULLABLE (FK сохраняется) — раньше NOT NULL исключал блог/стили/города;
 *  - page_type VARCHAR(16) DEFAULT 'brand' — классификация страницы (brand/blog/style/city/other),
 *    бэкафилл существующих строк через DEFAULT;
 *  - уникальность переносится с brand_id на page_url (одна строка на URL, не на бренд) —
 *    старый UNIQUE(brand_id) мешал бы хранить несколько локалей одного бренда отдельными строками.
 *
 * Идемпотентно: все ADD COLUMN/DROP INDEX/ADD INDEX через information_schema-guard
 * (MySQL не поддерживает ADD COLUMN/INDEX IF NOT EXISTS).
 */
final class Version20260707_index_all_pages extends AbstractMigration
{
    private const TABLES = ['yandex_index_status', 'gsc_index_status'];

    public function getDescription(): string
    {
        return 'yandex_index_status/gsc_index_status: brand_id nullable + page_type — трекинг всех страниц, не только брендов';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            // brand_id → NULLABLE
            $nullable = $this->connection->fetchOne(
                "SELECT is_nullable FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = 'brand_id'",
                ['t' => $table],
            );
            if ($nullable === 'NO') {
                $this->addSql("ALTER TABLE {$table} MODIFY COLUMN brand_id INT DEFAULT NULL");
            }

            // page_type
            $hasPageType = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = 'page_type'",
                ['t' => $table],
            );
            if ($hasPageType === 0) {
                $this->addSql("ALTER TABLE {$table} ADD COLUMN page_type VARCHAR(16) NOT NULL DEFAULT 'brand'");
            }

            // неуникальный индекс по brand_id (создаём ДО дропа старого unique — FK всегда
            // требует индекс на своей колонке, иначе MySQL #1553 при DROP INDEX)
            $brandIdx = $table === 'yandex_index_status' ? 'idx_yidx_brand' : 'idx_gsc_idx_brand';
            $hasBrandIdx = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i",
                ['t' => $table, 'i' => $brandIdx],
            );
            if ($hasBrandIdx === 0) {
                $this->addSql("CREATE INDEX {$brandIdx} ON {$table} (brand_id)");
            }

            // старый UNIQUE(brand_id) → мешает хранить несколько строк на бренд (разные локали/URL)
            $oldUnique = $table === 'yandex_index_status' ? 'uniq_yidx' : 'uniq_idx';
            $hasOldUnique = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i",
                ['t' => $table, 'i' => $oldUnique],
            );
            if ($hasOldUnique > 0) {
                $this->addSql("ALTER TABLE {$table} DROP INDEX {$oldUnique}");
            }

            // UNIQUE(page_url) — новый ключ идемпотентности upsert'а
            $urlIdx = $table === 'yandex_index_status' ? 'uniq_yidx_url' : 'uniq_gsc_idx_url';
            $hasUrlIdx = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i",
                ['t' => $table, 'i' => $urlIdx],
            );
            if ($hasUrlIdx === 0) {
                $this->addSql("CREATE UNIQUE INDEX {$urlIdx} ON {$table} (page_url)");
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $urlIdx   = $table === 'yandex_index_status' ? 'uniq_yidx_url' : 'uniq_gsc_idx_url';
            $brandIdx = $table === 'yandex_index_status' ? 'idx_yidx_brand' : 'idx_gsc_idx_brand';
            $oldUnique = $table === 'yandex_index_status' ? 'uniq_yidx' : 'uniq_idx';

            $this->addSql("DROP INDEX {$urlIdx} ON {$table}");
            $this->addSql("ALTER TABLE {$table} DROP COLUMN page_type");
            // сначала новый unique(brand_id), потом дроп idx_yidx_brand — FK всегда нужен индекс
            $this->addSql("CREATE UNIQUE INDEX {$oldUnique} ON {$table} (brand_id)");
            $this->addSql("DROP INDEX {$brandIdx} ON {$table}");
            $this->addSql("ALTER TABLE {$table} MODIFY COLUMN brand_id INT NOT NULL");
        }
    }
}
