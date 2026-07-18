<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * gsc_query_stats: второй Search Analytics pull, dimensions=['query','date']
 * (отдельно от gsc_page_stats, который берёт dimensions=['page','date'] и
 * оставляет query всегда NULL). Разблокирует regex-свип запросов под AI
 * Overviews (docs/seo_sitewide_backlog.md HIGH #2, docs/drmax_seo_2026_digest.md §5).
 */
final class Version20260719_gsc_query_stats extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gsc_query_stats — Search Analytics по query (для regex-свипа AI Overviews)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gsc_query_stats (
                id INT AUTO_INCREMENT NOT NULL,
                query VARCHAR(255) NOT NULL,
                day DATE NOT NULL,
                impressions INT NOT NULL DEFAULT 0,
                clicks INT NOT NULL DEFAULT 0,
                ctr DECIMAL(6,4) NOT NULL DEFAULT 0.0,
                position DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_gsc_query (query(191), day),
                INDEX idx_gsc_query_day (day)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gsc_query_stats');
    }
}
