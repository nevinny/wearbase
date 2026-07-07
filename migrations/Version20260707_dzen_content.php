<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * article.dzen_content / dzen_source_file: готовый под Дзен текст (другая персона,
 * TOC, in-text UTM-ссылки — var/seo/dzen/*.md), привязанный к статье-первоисточнику
 * через app:seo:attach-dzen-copy. Фид /rss/dzen.xml отдаёт dzen_content вместо
 * трансформации article.content — избегаем почти-дубля блогового текста на Дзене.
 *
 * Идемпотентно: ADD COLUMN только если столбца ещё нет.
 */
final class Version20260707_dzen_content extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'article: dzen_content + dzen_source_file (готовый текст под Дзен, привязанный к статье)';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'dzen_content'     => 'TEXT DEFAULT NULL',
            'dzen_source_file' => 'VARCHAR(255) DEFAULT NULL',
        ] as $col => $type) {
            $exists = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'article' AND column_name = :c",
                ['c' => $col],
            );
            if ($exists === 0) {
                $this->addSql("ALTER TABLE article ADD COLUMN {$col} {$type}");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP COLUMN dzen_content');
        $this->addSql('ALTER TABLE article DROP COLUMN dzen_source_file');
    }
}
