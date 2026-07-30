<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.variant — ветка A/B-эксперимента (logo_first|logo_last), NULL = вне эксперимента.
 * Отдельное поле, а не суффикс к рубрике: рубрика описывает тип контента, вариант — арм
 * эксперимента, и app:social:evaluate группирует по паре (рубрика, вариант).
 */
final class Version20260731_social_post_variant extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.variant — ветка A/B (логотип первым/последним слайдом)';
    }

    public function up(Schema $schema): void
    {
        // MySQL (в отличие от MariaDB) не знает ADD COLUMN/CREATE INDEX ... IF NOT EXISTS —
        // идемпотентность обеспечиваем проверкой information_schema.
        if (!$this->columnExists('variant')) {
            $this->addSql('ALTER TABLE social_post ADD variant VARCHAR(20) DEFAULT NULL');
        }
        if (!$this->indexExists('idx_social_post_rubric_variant')) {
            $this->addSql('CREATE INDEX idx_social_post_rubric_variant ON social_post (rubric, variant)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('idx_social_post_rubric_variant')) {
            $this->addSql('DROP INDEX idx_social_post_rubric_variant ON social_post');
        }
        if ($this->columnExists('variant')) {
            $this->addSql('ALTER TABLE social_post DROP variant');
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

    private function indexExists(string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['social_post', $index],
        );
    }
}
