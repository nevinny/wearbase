<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таймауты очереди премодерации (app:moderation:timeouts, прод 1×/день):
 * `brand_moderation.reminded_at` — троттлинг повторных TG-напоминаний админу про
 * зависшие `reviewed`-заявки (не чаще раза в 2 дня — см. Repository::findOverdueReviewed).
 */
final class Version20260830_moderation_timeouts extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_moderation.reminded_at + scheduled_command: app:moderation:timeouts (prod, 5 9 * * *)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('reminded_at')) {
            $this->addSql('ALTER TABLE brand_moderation ADD reminded_at DATETIME DEFAULT NULL');
        }

        $this->addSql(
            "INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES "
            . "('prod', 'Модерация: таймауты', 'app:moderation:timeouts --no-debug', '5 9 * * *', 1)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command LIKE 'app:moderation:timeouts%'");

        if ($this->columnExists('reminded_at')) {
            $this->addSql('ALTER TABLE brand_moderation DROP reminded_at');
        }
    }

    private function columnExists(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['brand_moderation', $column],
        );
    }
}
