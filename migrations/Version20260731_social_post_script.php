<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.script_key/script_json/slide_count — сценарий надписей v3 (SlideScriptComposer):
 * script_key фиксирует реализованную ступень лестницы хуков + источник битов (напр.
 * 'h2.city|b.rag2|c.save') для app:social:evaluate; script_json — сериализованный SlideScript,
 * переиспользуемый между каруселью и Reels одного бренда (LLM недетерминирован); slide_count —
 * число кадров, нужно для watch_ratio Reels ((slide_count−1)×1.5+3.0).
 */
final class Version20260731_social_post_script extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.script_key/script_json/slide_count — сценарий надписей v3';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('script_key')) {
            $this->addSql('ALTER TABLE social_post ADD script_key VARCHAR(48) DEFAULT NULL');
        }
        if (!$this->columnExists('script_json')) {
            $this->addSql('ALTER TABLE social_post ADD script_json LONGTEXT DEFAULT NULL');
        }
        if (!$this->columnExists('slide_count')) {
            $this->addSql('ALTER TABLE social_post ADD slide_count SMALLINT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('slide_count')) {
            $this->addSql('ALTER TABLE social_post DROP slide_count');
        }
        if ($this->columnExists('script_json')) {
            $this->addSql('ALTER TABLE social_post DROP script_json');
        }
        if ($this->columnExists('script_key')) {
            $this->addSql('ALTER TABLE social_post DROP script_key');
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
