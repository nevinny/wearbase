<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ai_usage_log.status/error — журнал теперь фиксирует и ОШИБКИ AI-запросов
 * (не только успешные, как раньше): status='ok'|'error', error — текст исключения
 * (обрезан до 255). Нужно для диагностики прод-сбоев (см. канал monolog wardrobe_ai).
 *
 * Идемпотентно: ADD COLUMN только если столбца ещё нет (MySQL не поддерживает
 * ADD COLUMN IF NOT EXISTS).
 */
final class Version20260712_ai_usage_log_status extends AbstractMigration
{
    public function getDescription(): string
    {
        return "ai_usage_log.status/error — учёт ошибочных AI-запросов";
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'ai_usage_log' AND column_name = 'status'",
        );
        if ($exists === 0) {
            $this->addSql("ALTER TABLE ai_usage_log ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'ok', ADD COLUMN error VARCHAR(255) DEFAULT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage_log DROP COLUMN status, DROP COLUMN error');
    }
}
