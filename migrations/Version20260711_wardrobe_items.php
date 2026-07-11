<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * wardrobe_item — «Мой гардероб»: личная инвентаризация вещей пользователя в ЛК.
 * Нумерация item_no сквозная per-user (включая soft-deleted), удаление — только deleted_at.
 * FK на client (таблица фронтового App\Entity\User), НЕ на админский user.
 */
final class Version20260711_wardrobe_items extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wardrobe_item — «Мой гардероб»: инвентаризация вещей пользователя в ЛК (soft-delete)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_item (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                item_no INT NOT NULL,
                category VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                size VARCHAR(50) DEFAULT NULL,
                price NUMERIC(10, 2) DEFAULT NULL,
                purchased_at DATE DEFAULT NULL,
                product_url VARCHAR(1000) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                purchase_reason LONGTEXT DEFAULT NULL,
                love_at_first_sight VARCHAR(10) DEFAULT NULL,
                pros LONGTEXT DEFAULT NULL,
                cons LONGTEXT DEFAULT NULL,
                verdict LONGTEXT DEFAULT NULL,
                photo VARCHAR(255) DEFAULT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'web',
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_wardrobe_user_item_no (user_id, item_no),
                INDEX idx_wardrobe_user_deleted (user_id, deleted_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_wardrobe_item_user FOREIGN KEY (user_id) REFERENCES client (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wardrobe_item');
    }
}
