<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ai_usage_log — append-only журнал расхода AI-запросов (токены + $) с привязкой
 * к пользователю. Фундамент под будущую перепродажу AI-кредитов — сама тарификация
 * здесь НЕ строится, только точный учёт. Системный лог: НЕ soft-delete.
 * user_id NULL = системный/пайплайн-вызов (не привязан к пользователю фронта).
 */
final class Version20260711_ai_usage_log extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ai_usage_log — журнал стоимости AI-запросов (токены + $) по пользователю/фиче';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ai_usage_log (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                feature VARCHAR(40) NOT NULL,
                model VARCHAR(100) NOT NULL,
                prompt_tokens INT NOT NULL DEFAULT 0,
                completion_tokens INT NOT NULL DEFAULT 0,
                cost_usd NUMERIC(12, 8) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ai_usage_log_user_created (user_id, created_at),
                INDEX idx_ai_usage_log_feature_created (feature, created_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_ai_usage_log_user FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_usage_log');
    }
}
