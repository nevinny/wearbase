<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Пакетная генерация образов «на утро» (app:wardrobe:daily-outfits + WardrobeDailyController):
 * occasion — повод генерации (work/theater/walk/meeting, см. WardrobeOutfit::DAILY_OCCASIONS),
 * NULL у образов, собранных интерактивно; deleted_at — soft-delete для идемпотентной замены
 * образов за тот же гардероб+повод+день (правило проекта: только soft-delete, физический
 * DELETE запрещён).
 *
 * NOTE: MySQL не поддерживает ADD COLUMN IF NOT EXISTS — идемпотентность через information_schema.
 */
final class Version20260913_wardrobe_daily_outfits extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wardrobe_outfit: occasion + deleted_at для пакетной генерации образов на утро';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('wardrobe_outfit', 'occasion')) {
            $this->addSql('ALTER TABLE wardrobe_outfit ADD occasion VARCHAR(20) DEFAULT NULL');
        }
        if (!$this->columnExists('wardrobe_outfit', 'deleted_at')) {
            $this->addSql('ALTER TABLE wardrobe_outfit ADD deleted_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('wardrobe_outfit', 'deleted_at')) {
            $this->addSql('ALTER TABLE wardrobe_outfit DROP COLUMN deleted_at');
        }
        if ($this->columnExists('wardrobe_outfit', 'occasion')) {
            $this->addSql('ALTER TABLE wardrobe_outfit DROP COLUMN occasion');
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
}
