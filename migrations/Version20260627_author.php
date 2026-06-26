<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E-E-A-T: таблица author (реальные авторы) + article.author_id.
 * Идемпотентно (IF NOT EXISTS / проверка колонки).
 */
final class Version20260627_author extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Author entity (E-E-A-T) + article.author_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS author (
                id              INT AUTO_INCREMENT NOT NULL,
                slug            VARCHAR(120) NOT NULL,
                name            VARCHAR(120) NOT NULL,
                job_title       VARCHAR(160) NOT NULL DEFAULT '',
                bio             LONGTEXT NOT NULL,
                photo           VARCHAR(255) DEFAULT NULL,
                photo_sm        VARCHAR(255) DEFAULT NULL,
                instagram_url   VARCHAR(255) DEFAULT NULL,
                school_name     VARCHAR(160) DEFAULT NULL,
                school_url      VARCHAR(255) DEFAULT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at      DATETIME DEFAULT NULL,
                updated_at      DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_author_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // article.author_id — добавить только если колонки нет
        $col = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'article' AND column_name = 'author_id'"
        );
        if ((int) $col === 0) {
            $this->addSql('ALTER TABLE article ADD author_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_article_author FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_article_author ON article (author_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY IF EXISTS FK_article_author');
        $this->addSql('DROP INDEX IF EXISTS IDX_article_author ON article');
        $this->addSql('ALTER TABLE article DROP COLUMN IF EXISTS author_id');
        $this->addSql('DROP TABLE IF EXISTS author');
    }
}
