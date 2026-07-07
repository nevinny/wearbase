<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * article_distribution — версионируемые копии статьи под внешние площадки (Дзен,
 * vc.ru, Пикабу…, см. GenerateListicleCommand::PLATFORM_TONES). Заменяет узкие
 * article.dzen_content/dzen_source_file (были только под Дзен и без версионирования):
 * одна статья может иметь копии под много площадок, и у каждой копии — историю версий
 * (перегенерация не затирает прошлый текст, is_current указывает на актуальную).
 *
 * Идемпотентно: CREATE TABLE IF NOT EXISTS.
 */
final class Version20260707_article_distribution extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'article_distribution — версионируемые копии статьи под внешние площадки (Дзен/vc/pikabu/…)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS article_distribution (
                id INT AUTO_INCREMENT NOT NULL,
                article_id INT NOT NULL,
                platform VARCHAR(32) NOT NULL,
                version INT NOT NULL DEFAULT 1,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                title VARCHAR(255) DEFAULT NULL,
                excerpt TEXT DEFAULT NULL,
                content TEXT NOT NULL,
                source_file VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_article_platform_version (article_id, platform, version),
                INDEX idx_article_platform_current (article_id, platform, is_current)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $fkExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = 'article_distribution'
               AND constraint_name = 'fk_article_distribution_article'",
        );
        if ($fkExists === 0) {
            $this->addSql(<<<'SQL'
                ALTER TABLE article_distribution
                ADD CONSTRAINT fk_article_distribution_article
                FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS article_distribution');
    }
}
