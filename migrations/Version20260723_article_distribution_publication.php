<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Факт ручной публикации конкретной версии ArticleDistribution на внешней площадке.
 * Nullable: существующие и ещё не опубликованные версии остаются без отметки.
 *
 * Идемпотентно: каждая колонка добавляется только если её ещё нет.
 */
final class Version20260723_article_distribution_publication extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'article_distribution.published_at/external_url — фиксация публикации конкретной версии';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('published_at')) {
            $this->addSql('ALTER TABLE article_distribution ADD published_at DATETIME DEFAULT NULL');
        }
        if (!$this->hasColumn('external_url')) {
            $this->addSql('ALTER TABLE article_distribution ADD external_url VARCHAR(512) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_distribution DROP COLUMN published_at, DROP COLUMN external_url');
    }

    private function hasColumn(string $column): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'article_distribution' AND column_name = :column",
            ['column' => $column],
        ) > 0;
    }
}
