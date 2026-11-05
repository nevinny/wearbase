<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Публикация IG через API castbase: переключатель канала social_channel.publish_via (local|castbase,
 * по умолчанию local — существующие каналы не меняются), id канала в castbase, id поста в castbase
 * и курсор ленты событий. Статус social_post 'handed_off' влезает в VARCHAR(20), DDL не нужен.
 */
final class Version20261105_castbase_ig_publishing extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_channel.publish_via/castbase_channel_id, social_post.castbase_post_id, castbase_sync_cursor';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('social_channel', 'publish_via')) {
            $this->addSql("ALTER TABLE social_channel ADD COLUMN publish_via VARCHAR(10) NOT NULL DEFAULT 'local'");
        }
        if (!$this->columnExists('social_channel', 'castbase_channel_id')) {
            $this->addSql('ALTER TABLE social_channel ADD COLUMN castbase_channel_id VARCHAR(36) DEFAULT NULL');
        }
        if (!$this->columnExists('social_post', 'castbase_post_id')) {
            $this->addSql('ALTER TABLE social_post ADD COLUMN castbase_post_id VARCHAR(36) DEFAULT NULL');
        }
        if (!$this->columnExists('social_post', 'castbase_outcome')) {
            $this->addSql('ALTER TABLE social_post ADD COLUMN castbase_outcome VARCHAR(20) DEFAULT NULL');
        }
        if (!$this->columnExists('social_post', 'castbase_generation')) {
            $this->addSql('ALTER TABLE social_post ADD COLUMN castbase_generation INT NOT NULL DEFAULT 0');
        }
        $this->addSql('CREATE TABLE IF NOT EXISTS castbase_sync_cursor (name VARCHAR(32) NOT NULL, seq INT NOT NULL DEFAULT 0, PRIMARY KEY(name)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS castbase_sync_cursor');
        if ($this->columnExists('social_post', 'castbase_outcome')) {
            $this->addSql('ALTER TABLE social_post DROP COLUMN castbase_outcome');
        }
        if ($this->columnExists('social_post', 'castbase_generation')) {
            $this->addSql('ALTER TABLE social_post DROP COLUMN castbase_generation');
        }
        if ($this->columnExists('social_post', 'castbase_post_id')) {
            $this->addSql('ALTER TABLE social_post DROP COLUMN castbase_post_id');
        }
        if ($this->columnExists('social_channel', 'castbase_channel_id')) {
            $this->addSql('ALTER TABLE social_channel DROP COLUMN castbase_channel_id');
        }
        if ($this->columnExists('social_channel', 'publish_via')) {
            $this->addSql('ALTER TABLE social_channel DROP COLUMN publish_via');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) > 0;
    }
}
