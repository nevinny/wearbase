<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Паритет мониторинга Яндекса с Google (app:yandex:sync ← app:gsc:sync):
 *
 * 1. yandex_query_page — «какой наш URL Яндекс показывает по запросу». Берётся из POST
 *    query-analytics/list (там у каждой строки есть popular_complementary_indicator=URL);
 *    в search-queries/popular URL нет вообще, поэтому дожим позиций по Яндексу не знал,
 *    что править. Полный аналог gsc_query_page: оконный снимок, без разбивки по дням.
 *    Позиции здесь нет (API отдаёт IMPRESSIONS/CLICKS/CTR/DEMAND) — она живёт в
 *    yandex_query_stats, связка по тексту запроса.
 *
 * 2. yandex_site_health — суточный снимок здоровья хоста: ИКС, страниц в поиске,
 *    исключено, число проблем по важности и список активных проблем диагностики
 *    (там же живут НАРУШЕНИЯ, включая малополезный контент). Нужен для тренда и алерта:
 *    одно число «сколько страниц в поиске» без истории ничего не говорит.
 */
final class Version20260728_yandex_query_page_health extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'yandex_query_page (запрос→URL) + yandex_site_health (ИКС/индекс/диагностика)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS yandex_query_page (
                id INT AUTO_INCREMENT NOT NULL,
                query VARCHAR(255) NOT NULL,
                page_url VARCHAR(512) NOT NULL COMMENT 'путь как отдаёт Вебмастер (без домена)',
                impressions INT NOT NULL DEFAULT 0,
                clicks INT NOT NULL DEFAULT 0,
                demand INT NOT NULL DEFAULT 0 COMMENT 'DEMAND — частотность запроса в Яндексе, не наш показатель',
                captured_on DATE NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_yandex_query_page (query(191), page_url(191)),
                INDEX idx_yandex_query_page_query (query(191), impressions)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS yandex_site_health (
                id INT AUTO_INCREMENT NOT NULL,
                captured_on DATE NOT NULL,
                sqi INT NOT NULL DEFAULT 0 COMMENT 'ИКС',
                searchable_pages INT NOT NULL DEFAULT 0,
                excluded_pages INT NOT NULL DEFAULT 0,
                problems_json VARCHAR(1024) DEFAULT NULL COMMENT 'счётчики по важности + активные коды диагностики',
                broken_internal_links INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_yandex_site_health (captured_on)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS yandex_query_page');
        $this->addSql('DROP TABLE IF EXISTS yandex_site_health');
    }
}
