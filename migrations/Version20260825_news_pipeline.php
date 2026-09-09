<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Подсистема «парсер → рерайт → модерация»: справочник источников
 * и очередь новостей. Источники MVP сидируются сразу (_docs/news-sources.md).
 */
final class Version20260825_news_pipeline extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'News pipeline: news_source catalog (seeded) and news_item queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE news_source (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                feed_url VARCHAR(512) NOT NULL,
                tos_mode VARCHAR(16) DEFAULT 'facts_only' NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                rubric_hint VARCHAR(64) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                UNIQUE INDEX UNIQ_news_source_name (name),
                UNIQUE INDEX UNIQ_news_source_feed_url (feed_url),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE news_item (
                id INT AUTO_INCREMENT NOT NULL,
                source_id INT NOT NULL,
                guid_hash CHAR(64) NOT NULL,
                url VARCHAR(512) NOT NULL,
                slug VARCHAR(255) DEFAULT NULL,
                title VARCHAR(512) NOT NULL,
                source_name VARCHAR(255) NOT NULL,
                source_url VARCHAR(512) NOT NULL,
                published_at DATETIME DEFAULT NULL,
                raw_fetched_text LONGTEXT DEFAULT NULL,
                rewritten_title VARCHAR(512) DEFAULT NULL,
                rewritten_body LONGTEXT DEFAULT NULL,
                ready_at DATETIME DEFAULT NULL,
                rubric VARCHAR(16) DEFAULT NULL,
                shingle_score DOUBLE PRECISION DEFAULT NULL,
                status VARCHAR(16) DEFAULT 'discovered' NOT NULL,
                status_timestamps LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                reject_reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                INDEX IDX_news_item_status (status),
                INDEX IDX_news_item_slug (slug),
                UNIQUE INDEX UNIQ_news_item_slug (slug),
                UNIQUE INDEX UNIQ_news_item_source_guid (source_id, guid_hash),
                PRIMARY KEY(id),
                CONSTRAINT FK_news_item_source FOREIGN KEY (source_id) REFERENCES news_source (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Сид источников MVP: 4 facts_only активных + 2 forbidden выключенных
        // (_docs/news-sources.md §1, _docs/news-sources-tos.md §3).
        // Список синхронизирован с App\Service\News\NewsSourcesCatalog (сид-команда).
        $this->addSql("INSERT INTO news_source (name, feed_url, tos_mode, active, rubric_hint, created_at, updated_at) VALUES\n" . implode(",\n", [
            "('Parents.ru', 'https://www.parents.ru/rss-feeds/rss.xml', 'facts_only', 1, 'дети', NOW(), NOW())",
            "('Woman.ru', 'https://www.woman.ru/rss/', 'facts_only', 1, 'мода', NOW(), NOW())",
            "('The-Day', 'https://the-day.ru/rss-feeds/rss.xml', 'facts_only', 1, NULL, NOW(), NOW())",
            "('Sobaka.ru', 'https://www.sobaka.ru/rss/news.xml', 'facts_only', 1, NULL, NOW(), NOW())",
            "('Buro 24/7', 'https://www.buro247.ru/xml/rss.xml', 'forbidden', 0, NULL, NOW(), NOW())",
            "('РБК Стиль', 'https://style.rbc.ru/', 'forbidden', 0, NULL, NOW(), NOW())",
        ]));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE news_item');
        $this->addSql('DROP TABLE news_source');
    }
}
