<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724_phase_wardrobe_details extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wardrobe quick/full cards, completion statuses and category children';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            'custom_brand_name' => 'VARCHAR(255) DEFAULT NULL',
            'color_name' => 'VARCHAR(100) DEFAULT NULL',
            'material_text' => 'LONGTEXT DEFAULT NULL',
            'country_of_origin' => 'VARCHAR(100) DEFAULT NULL',
            'season' => 'VARCHAR(50) DEFAULT NULL',
            'care_text' => 'LONGTEXT DEFAULT NULL',
            'completion_status' => "VARCHAR(12) NOT NULL DEFAULT 'draft'",
            'item_status' => "VARCHAR(12) NOT NULL DEFAULT 'active'",
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->columnExists('wardrobe_item', $name)) {
                $this->addSql(sprintf('ALTER TABLE wardrobe_item ADD COLUMN %s %s', $name, $definition));
            }
        }

        $this->addSql('ALTER TABLE wardrobe_item MODIFY category VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE wardrobe_item MODIFY name VARCHAR(255) DEFAULT NULL');

        $children = [
            ['tops', 'tshirt', 'Футболка', 11],
            ['tops', 'tank_top', 'Майка', 12],
            ['tops', 'top', 'Топ', 13],
            ['tops', 'shirt', 'Рубашка', 14],
            ['tops', 'polo', 'Поло', 15],
            ['tops', 'longsleeve', 'Лонгслив', 16],
            ['tops', 'turtleneck', 'Водолазка', 17],
            ['tops', 'hoodie', 'Худи', 18],
            ['tops', 'sweatshirt', 'Свитшот', 19],
            ['tops', 'vest', 'Жилет', 20],
            ['tops', 'jumper', 'Джемпер', 21],
            ['tops', 'cardigan', 'Кардиган', 22],
            ['tops', 'blazer', 'Жакет', 23],
            ['bottoms', 'trousers', 'Брюки', 31],
            ['bottoms', 'jeans', 'Джинсы', 32],
            ['bottoms', 'skirt', 'Юбка', 33],
            ['bottoms', 'shorts', 'Шорты', 34],
            ['dresses', 'dress', 'Платье', 41],
            ['outerwear', 'jacket', 'Куртка', 51],
            ['outerwear', 'coat', 'Пальто', 52],
            ['outerwear', 'raincoat', 'Плащ', 53],
            ['footwear', 'shoes', 'Туфли', 61],
            ['footwear', 'ankle_boots', 'Ботильоны', 62],
            ['footwear', 'boots', 'Ботинки', 63],
            ['footwear', 'high_boots', 'Сапоги', 64],
            ['footwear', 'sneakers', 'Кроссовки', 65],
            ['accessories', 'belt', 'Ремень', 71],
            ['accessories', 'hat', 'Шапка', 72],
            ['accessories', 'scarf', 'Шарф', 73],
            ['bags', 'bag', 'Сумка', 81],
        ];

        foreach ($children as [$parentCode, $code, $name, $sortOrder]) {
            $this->addSql(
                'INSERT IGNORE INTO wardrobe_category (parent_id, code, name, sort_order, is_active, created_at, updated_at)
                 SELECT id, ?, ?, ?, 1, NOW(), NOW() FROM wardrobe_category WHERE code = ?',
                [$code, $name, $sortOrder, $parentCode],
            );
        }

        $this->addSql(<<<'SQL'
            UPDATE wardrobe_item
            SET completion_status = CASE
                WHEN name IS NOT NULL AND name <> ''
                    AND category IS NOT NULL AND category <> ''
                    AND (photo IS NOT NULL OR product_url IS NOT NULL)
                    AND (size IS NOT NULL AND size <> '')
                THEN 'basic'
                ELSE 'draft'
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM wardrobe_category WHERE parent_id IS NOT NULL");
        $this->addSql('ALTER TABLE wardrobe_item MODIFY category VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE wardrobe_item MODIFY name VARCHAR(255) NOT NULL');
        foreach (['custom_brand_name', 'color_name', 'material_text', 'country_of_origin', 'season', 'care_text', 'completion_status', 'item_status'] as $column) {
            $this->addSql(sprintf('ALTER TABLE wardrobe_item DROP COLUMN %s', $column));
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
