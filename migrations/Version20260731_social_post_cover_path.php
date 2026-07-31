<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.cover_path — обложка Reels (параметр cover_url у контейнера REELS).
 * Без неё Instagram берёт первый кадр клипа, а он зависит от ветки A/B (logo_first →
 * обложкой становится карточка логотипа), то есть эксперимент сравнивал бы ещё и обложку.
 * Отдельное поле, а не второй элемент media_path: media_path — это сам материал поста.
 */
final class Version20260731_social_post_cover_path extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.cover_path — обложка Reels (cover_url), одинаковая в обеих ветках A/B';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('cover_path')) {
            $this->addSql('ALTER TABLE social_post ADD cover_path VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('cover_path')) {
            $this->addSql('ALTER TABLE social_post DROP cover_path');
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
