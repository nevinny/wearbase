<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица product_image была создана вручную без колонок из трейта DefaultFields
 * (slug, title, parent, ord), которые Doctrine ожидает при SELECT.
 *
 * Добавляем недостающие колонки через процедуру, чтобы миграция была идемпотентной
 * (повторный запуск не падает, если колонки уже существуют).
 */
final class Version20260524_fix_product_image_schema extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix product_image table: add missing DefaultFields columns (slug, title, parent, ord)';
    }

    public function up(Schema $schema): void
    {
        // MySQL не поддерживает IF NOT EXISTS для ALTER TABLE ADD COLUMN,
        // поэтому используем хранимую процедуру для идемпотентности.
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_add_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_add_col(
                IN tbl  VARCHAR(64),
                IN col  VARCHAR(64),
                IN def  TEXT
            )
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM   information_schema.COLUMNS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = tbl
                      AND  COLUMN_NAME  = col
                ) THEN
                    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
                    PREPARE stmt FROM @sql;
                    EXECUTE stmt;
                    DEALLOCATE PREPARE stmt;
                END IF;
            END
        SQL);

        // slug — обязательное поле в DefaultFields (NOT NULL, но у нас legacy-таблица — ставим DEFAULT '')
        $this->addSql("CALL wb_add_col('product_image', 'slug',   'VARCHAR(255) NOT NULL DEFAULT \'\'')");
        $this->addSql("CALL wb_add_col('product_image', 'title',  'VARCHAR(255) DEFAULT NULL')");
        $this->addSql("CALL wb_add_col('product_image', 'parent', 'INT DEFAULT NULL')");
        $this->addSql("CALL wb_add_col('product_image', 'ord',    'INT NOT NULL DEFAULT 0')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_add_col');
    }

    public function down(Schema $schema): void
    {
        // Удаляем добавленные колонки только если они существуют
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_drop_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_drop_col(
                IN tbl VARCHAR(64),
                IN col VARCHAR(64)
            )
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM   information_schema.COLUMNS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = tbl
                      AND  COLUMN_NAME  = col
                ) THEN
                    SET @sql = CONCAT('ALTER TABLE `', tbl, '` DROP COLUMN `', col, '`');
                    PREPARE stmt FROM @sql;
                    EXECUTE stmt;
                    DEALLOCATE PREPARE stmt;
                END IF;
            END
        SQL);

        $this->addSql("CALL wb_drop_col('product_image', 'ord')");
        $this->addSql("CALL wb_drop_col('product_image', 'parent')");
        $this->addSql("CALL wb_drop_col('product_image', 'title')");
        $this->addSql("CALL wb_drop_col('product_image', 'slug')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_drop_col');
    }
}
