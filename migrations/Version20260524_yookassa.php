<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_yookassa extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gateway_payment_id column to order table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_add_payment_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_add_payment_col(
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

        $this->addSql("CALL wb_add_payment_col('order', 'gateway_payment_id', 'VARCHAR(255) DEFAULT NULL')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_add_payment_col');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_drop_payment_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_drop_payment_col(
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

        $this->addSql("CALL wb_drop_payment_col('`order`', 'gateway_payment_id')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_drop_payment_col');
    }
}
