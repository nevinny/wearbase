<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_user_tokens extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_verification_token, password_reset_token, password_reset_requested_at columns to client table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_add_token_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_add_token_col(
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

        $this->addSql("CALL wb_add_token_col('client', 'email_verification_token',   'VARCHAR(64) DEFAULT NULL UNIQUE')");
        $this->addSql("CALL wb_add_token_col('client', 'password_reset_token',       'VARCHAR(64) DEFAULT NULL UNIQUE')");
        $this->addSql("CALL wb_add_token_col('client', 'password_reset_requested_at', 'DATETIME DEFAULT NULL COMMENT \"(DC2Type:datetime_immutable)\"')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_add_token_col');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP PROCEDURE IF EXISTS wb_drop_token_col
        SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE wb_drop_token_col(
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

        $this->addSql("CALL wb_drop_token_col('client', 'email_verification_token')");
        $this->addSql("CALL wb_drop_token_col('client', 'password_reset_token')");
        $this->addSql("CALL wb_drop_token_col('client', 'password_reset_requested_at')");

        $this->addSql('DROP PROCEDURE IF EXISTS wb_drop_token_col');
    }
}
