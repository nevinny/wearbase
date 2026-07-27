<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723_phase_wardrobe_mvp extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wardrobe MVP foundation: default wardrobes, category dictionary and compatible item references';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe (
                id INT AUTO_INCREMENT NOT NULL,
                owner_user_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'personal',
                is_default TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME DEFAULT NULL,
                INDEX idx_wardrobe_owner_default (owner_user_id, is_default, deleted_at),
                CONSTRAINT fk_wardrobe_owner FOREIGN KEY (owner_user_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_category (
                id INT AUTO_INCREMENT NOT NULL,
                parent_id INT DEFAULT NULL,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_wardrobe_category_code (code),
                INDEX idx_wardrobe_category_parent (parent_id),
                CONSTRAINT fk_wardrobe_category_parent FOREIGN KEY (parent_id) REFERENCES wardrobe_category (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $categories = [
            ['tops', 'Верх', 10],
            ['bottoms', 'Низ', 20],
            ['dresses', 'Платья', 30],
            ['outerwear', 'Верхняя одежда', 40],
            ['footwear', 'Обувь', 50],
            ['bags', 'Сумки', 60],
            ['accessories', 'Аксессуары', 70],
            ['underwear', 'Бельё', 80],
            ['homewear', 'Домашняя одежда', 90],
            ['sport', 'Спорт', 100],
        ];
        foreach ($categories as [$code, $name, $sortOrder]) {
            $this->addSql(
                'INSERT IGNORE INTO wardrobe_category (code, name, sort_order, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, 1, NOW(), NOW())',
                [$code, $name, $sortOrder],
            );
        }

        if (!$this->columnExists('wardrobe_item', 'wardrobe_id')) {
            $this->addSql('ALTER TABLE wardrobe_item ADD COLUMN wardrobe_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE wardrobe_item ADD INDEX idx_wardrobe_item_wardrobe (wardrobe_id)');
            $this->addSql('ALTER TABLE wardrobe_item ADD CONSTRAINT fk_wardrobe_item_wardrobe FOREIGN KEY (wardrobe_id) REFERENCES wardrobe (id)');
        }
        if (!$this->columnExists('wardrobe_item', 'category_ref_id')) {
            $this->addSql('ALTER TABLE wardrobe_item ADD COLUMN category_ref_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE wardrobe_item ADD INDEX idx_wardrobe_item_category_ref (category_ref_id)');
            $this->addSql('ALTER TABLE wardrobe_item ADD CONSTRAINT fk_wardrobe_item_category_ref FOREIGN KEY (category_ref_id) REFERENCES wardrobe_category (id)');
        }

        $this->addSql(<<<'SQL'
            INSERT INTO wardrobe (owner_user_id, name, type, is_default, status, created_at, updated_at)
            SELECT DISTINCT wi.user_id, 'Мой гардероб', 'personal', 1, 'active', NOW(), NOW()
            FROM wardrobe_item wi
            WHERE NOT EXISTS (
                SELECT 1 FROM wardrobe w
                WHERE w.owner_user_id = wi.user_id AND w.is_default = 1 AND w.deleted_at IS NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE wardrobe_item wi
            JOIN wardrobe w ON w.owner_user_id = wi.user_id AND w.is_default = 1 AND w.deleted_at IS NULL
            SET wi.wardrobe_id = w.id
            WHERE wi.wardrobe_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item DROP FOREIGN KEY fk_wardrobe_item_category_ref');
        $this->addSql('ALTER TABLE wardrobe_item DROP COLUMN category_ref_id');
        $this->addSql('ALTER TABLE wardrobe_item DROP FOREIGN KEY fk_wardrobe_item_wardrobe');
        $this->addSql('ALTER TABLE wardrobe_item DROP COLUMN wardrobe_id');
        $this->addSql('DROP TABLE IF EXISTS wardrobe_category');
        $this->addSql('DROP TABLE IF EXISTS wardrobe');
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
