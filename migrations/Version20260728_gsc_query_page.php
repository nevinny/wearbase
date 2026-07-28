<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * gsc_query_page — «какой наш URL ранжируется по этому запросу» (Search Analytics,
 * dimensions=['query','page'], без разбивки по дням — см. GscClient::searchAnalyticsByQueryPage).
 *
 * Почему отдельная таблица, а не колонка query в gsc_page_stats: там `day` — суточный
 * агрегат по странице, а здесь строка описывает окно целиком. Писать окно в поле дня
 * значило бы врать про семантику `day` (gsc_page_stats.query поэтому и остаётся NULL).
 *
 * Потребители: app:seo:gap-report --band=striking (какой URL дожимать) и — на 2+ URL
 * с показами по одному запросу — сигнал каннибализации.
 */
final class Version20260728_gsc_query_page extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gsc_query_page — срез запрос×URL из Search Analytics (URL-владелец запроса)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gsc_query_page (
                id INT AUTO_INCREMENT NOT NULL,
                query VARCHAR(255) NOT NULL,
                page_url VARCHAR(512) NOT NULL,
                impressions INT NOT NULL DEFAULT 0,
                clicks INT NOT NULL DEFAULT 0,
                position DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                captured_on DATE NOT NULL COMMENT 'дата последнего синка (окно GSC ~7 дней)',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_gsc_query_page (query(191), page_url(191)),
                INDEX idx_gsc_query_page_query (query(191), impressions)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gsc_query_page');
    }
}
