<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_product_slug_per_brand extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make product slug unique per brand instead of globally unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_slug_fix
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_slug_fix()
            BEGIN
                -- Drop global unique index on slug if it exists
                IF EXISTS (
                    SELECT 1
                    FROM   information_schema.STATISTICS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = 'product'
                      AND  COLUMN_NAME  = 'slug'
                      AND  NON_UNIQUE   = 0
                      AND  INDEX_NAME   = 'UNIQ_D34A04AD989D9B62'
                ) THEN
                    ALTER TABLE `product` DROP INDEX `UNIQ_D34A04AD989D9B62`;
                END IF;

                -- Drop old unique index by name variation (InnoDB auto-naming)
                IF EXISTS (
                    SELECT 1
                    FROM   information_schema.STATISTICS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = 'product'
                      AND  COLUMN_NAME  = 'slug'
                      AND  NON_UNIQUE   = 0
                ) THEN
                    SET @index_name = (
                        SELECT INDEX_NAME
                        FROM   information_schema.STATISTICS
                        WHERE  TABLE_SCHEMA = DATABASE()
                          AND  TABLE_NAME   = 'product'
                          AND  COLUMN_NAME  = 'slug'
                          AND  NON_UNIQUE   = 0
                        LIMIT 1
                    );
                    SET @sql = CONCAT('ALTER TABLE `product` DROP INDEX `', @index_name, '`');
                    PREPARE stmt FROM @sql;
                    EXECUTE stmt;
                    DEALLOCATE PREPARE stmt;
                END IF;

                -- Add composite unique index on (brand_id, slug)
                IF NOT EXISTS (
                    SELECT 1
                    FROM   information_schema.STATISTICS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = 'product'
                      AND  INDEX_NAME   = 'brand_slug_unique'
                ) THEN
                    ALTER TABLE `product` ADD UNIQUE INDEX `brand_slug_unique` (`brand_id`, `slug`);
                END IF;
            END
        SQL);

        $this->addSql('CALL wb_slug_fix()');
        $this->addSql('DROP PROCEDURE IF EXISTS wb_slug_fix');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_revert_slug
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_revert_slug()
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM   information_schema.STATISTICS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = 'product'
                      AND  INDEX_NAME   = 'brand_slug_unique'
                ) THEN
                    ALTER TABLE `product` DROP INDEX `brand_slug_unique`;
                END IF;

                -- Re-add global unique on slug
                IF NOT EXISTS (
                    SELECT 1
                    FROM   information_schema.STATISTICS
                    WHERE  TABLE_SCHEMA = DATABASE()
                      AND  TABLE_NAME   = 'product'
                      AND  COLUMN_NAME  = 'slug'
                      AND  NON_UNIQUE   = 0
                ) THEN
                    ALTER TABLE `product` ADD UNIQUE INDEX `UNIQ_D34A04AD989D9B62` (`slug`);
                END IF;
            END
        SQL);

        $this->addSql('CALL wb_revert_slug()');
        $this->addSql('DROP PROCEDURE IF EXISTS wb_revert_slug');
    }
}
