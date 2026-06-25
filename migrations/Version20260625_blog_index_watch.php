<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Поля article для closed-loop «блог→индексация→Дзен»:
 *  - source_file: имя исходного .md (var/seo/blog/...) → из него вотчер выводит путь
 *    Дзен-варианта (swap -site→-dzen);
 *  - indexed_at: когда GSC-вотчер впервые увидел страницу в индексе;
 *  - indexed_notified_at: когда отправлено TG-уведомление «готово к Дзену» (антиповтор).
 *
 * Идемпотентно: ADD COLUMN только если столбца ещё нет (MySQL не поддерживает
 * ADD COLUMN IF NOT EXISTS).
 */
final class Version20260625_blog_index_watch extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'article: source_file + indexed_at + indexed_notified_at (closed-loop блог→Дзен)';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'source_file'         => 'VARCHAR(255) DEFAULT NULL',
            'indexed_at'          => 'DATETIME DEFAULT NULL',
            'indexed_notified_at' => 'DATETIME DEFAULT NULL',
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
        $this->addSql('ALTER TABLE article DROP COLUMN source_file');
        $this->addSql('ALTER TABLE article DROP COLUMN indexed_at');
        $this->addSql('ALTER TABLE article DROP COLUMN indexed_notified_at');
    }
}
