<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260726_wardrobe_admin_styles extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connect wardrobe items to the existing admin BrandStyle directory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_item_style (
                wardrobe_item_id INT NOT NULL,
                brand_style_id INT NOT NULL,
                INDEX idx_wardrobe_item_style_item (wardrobe_item_id),
                INDEX idx_wardrobe_item_style_style (brand_style_id),
                PRIMARY KEY(wardrobe_item_id, brand_style_id),
                CONSTRAINT fk_wardrobe_item_style_item FOREIGN KEY (wardrobe_item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE,
                CONSTRAINT fk_wardrobe_item_style_style FOREIGN KEY (brand_style_id) REFERENCES brand_style (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Удаляем временное JSON-поле нового справочника, если ранняя версия
        // изменения успела примениться локально.
        if ($this->columnExists('wardrobe_item', 'main_style')) {
            $this->addSql('ALTER TABLE wardrobe_item DROP COLUMN main_style');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Style relations may contain user selections.');
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
