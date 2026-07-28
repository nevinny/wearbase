<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * seo_gap_snapshot += band: одна и та же (source, intent_group) теперь считается в двух
 * полосах позиций (app:seo:gap-report --band) — `striking` (4–10) и `gap` (>10).
 * DEFAULT 'gap' задан намеренно: всё, что команда писала до сих пор, — это именно gap,
 * поэтому исторические строки остаются сравнимыми с новыми без бэкафилла.
 * Уникальный ключ расширяется на band, иначе striking затирал бы gap за тот же день.
 */
final class Version20260728_seo_gap_snapshot_band extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'seo_gap_snapshot += band (striking|gap) + расширение уникального ключа';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('seo_gap_snapshot', 'band')) {
            $this->addSql("ALTER TABLE seo_gap_snapshot ADD COLUMN band VARCHAR(20) NOT NULL DEFAULT 'gap' COMMENT 'полоса позиций: striking (4-10) | gap (>10)'");
        }

        // Пересборка уникального ключа: (captured_on, source, intent_group) → + band.
        if ($this->indexExists('seo_gap_snapshot', 'uniq_seo_gap_snapshot')) {
            $this->addSql('ALTER TABLE seo_gap_snapshot DROP INDEX uniq_seo_gap_snapshot');
        }
        if (!$this->indexExists('seo_gap_snapshot', 'uniq_seo_gap_snapshot_band')) {
            $this->addSql('ALTER TABLE seo_gap_snapshot ADD UNIQUE INDEX uniq_seo_gap_snapshot_band (captured_on, source, band, intent_group)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('seo_gap_snapshot', 'uniq_seo_gap_snapshot_band')) {
            $this->addSql('ALTER TABLE seo_gap_snapshot DROP INDEX uniq_seo_gap_snapshot_band');
        }
        if ($this->columnExists('seo_gap_snapshot', 'band')) {
            $this->addSql('ALTER TABLE seo_gap_snapshot DROP COLUMN band');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index],
        ) > 0;
    }
}
