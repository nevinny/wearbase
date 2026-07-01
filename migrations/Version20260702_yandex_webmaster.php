<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Яндекс.Вебмастер (RU-аналог GSC):
 *  - yandex_index_status: бренды, СЕЙЧАС в поиске Яндекса (search-urls/in-search/samples),
 *                         одна строка на бренд (перезаписывается). Coverage Яндекса, в отличие
 *                         от GSC, не заморожен → живой индекс-сигнал по RU-рынку.
 *  - yandex_query_stats:  TOP популярных запросов за неделю (search-queries/popular),
 *                         показы/клики/позиция — RU-аналог GSC Search Analytics.
 */
final class Version20260702_yandex_webmaster extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'yandex_index_status + yandex_query_stats — данные Яндекс.Вебмастера';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS yandex_index_status (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                page_url VARCHAR(512) NOT NULL,
                in_search TINYINT(1) NOT NULL DEFAULT 0,
                last_checked_at DATETIME DEFAULT NULL,
                first_seen_at DATETIME DEFAULT NULL COMMENT 'первое появление в поиске Яндекса',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_yidx (brand_id),
                INDEX idx_yidx_checked (last_checked_at),
                CONSTRAINT fk_yidx_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS yandex_query_stats (
                id INT AUTO_INCREMENT NOT NULL,
                query_text VARCHAR(255) NOT NULL,
                shows INT NOT NULL DEFAULT 0,
                clicks INT NOT NULL DEFAULT 0,
                position DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                date_from DATE DEFAULT NULL,
                date_to DATE NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_yq (query_text(191), date_to),
                INDEX idx_yq_to (date_to)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS yandex_query_stats');
        $this->addSql('DROP TABLE IF EXISTS yandex_index_status');
    }
}
