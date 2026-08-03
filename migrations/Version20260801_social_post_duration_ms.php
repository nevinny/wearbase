<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.duration_ms — фактическая длительность Reels в мс (NULL для карусели/картинки).
 * P0-2 (§9 №2 reels_viral_playbook.md): SocialEvaluateCommand делил avg_watch_ms на оценку
 * задним числом ((slide_count−1)×1500+3000) — верную только для ровного профиля 1.5с/слайд.
 * С появлением пер-слайдовой длительности (P0-1, E1: hook_hold vs flat_150) эта формула стала
 * бы ложью для новых постов — духа основной метрики watch_ratio ради тайминг-эксперимента.
 */
final class Version20260801_social_post_duration_ms extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.duration_ms — фактическая длительность Reels в мс (P0-2)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('duration_ms')) {
            $this->addSql('ALTER TABLE social_post ADD duration_ms INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('duration_ms')) {
            $this->addSql('ALTER TABLE social_post DROP duration_ms');
        }
    }

    private function columnExists(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['social_post', $column],
        );
    }
}
