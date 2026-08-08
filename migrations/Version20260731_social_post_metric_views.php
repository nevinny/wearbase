<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post_metric: views + avg_watch_ms — то, что отдаёт Instagram по Reels и чего в таблице
 * не было. avg_watch_ms (метрика ig_reels_avg_watch_time, миллисекунды) — единственный прямой
 * показатель удержания: на первом замере вышло 3143 мс на клипе длиной 27 с, то есть 11.6%.
 * Без этой колонки эффект правок темпа/хука/счётчика измерить нечем.
 */
final class Version20260731_social_post_metric_views extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post_metric.views + avg_watch_ms (просмотры и удержание Reels)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('views')) {
            $this->addSql('ALTER TABLE social_post_metric ADD views INT DEFAULT 0 NOT NULL');
        }
        if (!$this->columnExists('avg_watch_ms')) {
            $this->addSql('ALTER TABLE social_post_metric ADD avg_watch_ms INT DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('avg_watch_ms')) {
            $this->addSql('ALTER TABLE social_post_metric DROP avg_watch_ms');
        }
        if ($this->columnExists('views')) {
            $this->addSql('ALTER TABLE social_post_metric DROP views');
        }
    }

    private function columnExists(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['social_post_metric', $column],
        );
    }
}
